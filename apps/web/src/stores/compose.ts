import { ref, watch } from 'vue'
import { defineStore } from 'pinia'
import {
  forwardSubject,
  isPlausibleAddress,
  quotedOriginal,
  replyRecipients,
  replySubject,
  type Address,
  type SendMessageRequest,
  type SyncedMessage,
} from '@tresso/core'
import { ApiError, errorMessage } from '@/api/errors'
import type { Draft } from '@/mail/db'
import { useMailStore } from '@/stores/mail'

/** Seconds a sent message waits before it goes out, so "Undo" can still stop it. */
export const UNDO_SECONDS = 5
const AUTOSAVE_MS = 800

export interface Notice {
  id: number
  text: string
  tone: 'info' | 'error'
  action?: { label: string; run: () => void }
}

type DraftKind = Draft['kind']

function newDraft(kind: DraftKind, mailAccountId: string | null): Draft {
  return {
    id: crypto.randomUUID(),
    mailAccountId,
    to: [],
    cc: [],
    bcc: [],
    subject: '',
    html: '',
    inReplyToMessageId: null,
    kind,
    outgoingId: null,
    noticedStatus: null,
    updatedAt: Date.now(),
  }
}

/**
 * What the user is writing. Drafts are saved on this device while typing and kept until the message is confirmed
 * sent, so nothing is lost on a reload and a failed message can be edited and sent again.
 */
export const useComposeStore = defineStore('compose', () => {
  const mail = useMailStore()

  const draft = ref<Draft | null>(null)
  const minimized = ref(false)
  const savedAt = ref<number | null>(null)
  const sending = ref(false)
  const error = ref<string | null>(null)
  const violations = ref<Record<string, string>>({})
  const notices = ref<Notice[]>([])
  const undoable = ref<{ outgoingId: string; draftId: string; until: number } | null>(null)

  let saveTimer: ReturnType<typeof setTimeout> | null = null
  let undoTimer: ReturnType<typeof setTimeout> | null = null
  let noticeId = 0

  function notify(notice: Omit<Notice, 'id'>, ms = 8000): void {
    const id = ++noticeId
    notices.value = [...notices.value, { ...notice, id }]
    setTimeout(() => dismiss(id), ms)
  }

  function dismiss(id: number): void {
    notices.value = notices.value.filter((notice) => notice.id !== id)
  }

  function defaultAccount(): string | null {
    return mail.mailbox ?? mail.accounts[0]?.id ?? null
  }

  function open(next: Draft): void {
    draft.value = next
    minimized.value = false
    error.value = null
    violations.value = {}
    savedAt.value = null
  }

  function openNew(to: Address[] = []): void {
    open({ ...newDraft('new', defaultAccount()), to })
  }

  async function openReply(message: SyncedMessage, kind: 'reply' | 'replyAll' | 'forward'): Promise<void> {
    const body = await mail.body(message.id, false).catch(() => ({ html: null, text: message.snippet }))
    const next = newDraft(kind, message.mailAccountId)
    next.inReplyToMessageId = message.id
    if (kind === 'forward') {
      next.subject = forwardSubject(message.subject)
    } else {
      const recipients = replyRecipients(message, mail.accounts.map((account) => account.emailAddress), kind === 'replyAll')
      next.to = recipients.to
      next.cc = recipients.cc
      next.subject = replySubject(message.subject)
    }
    next.html = `<p></p>${quotedOriginal(message, body, kind === 'forward' ? 'forward' : 'reply')}`
    open(next)
  }

  async function openDraft(id: string): Promise<void> {
    const stored = await mail.draft(id)
    if (stored) {
      open({ ...stored, outgoingId: null, noticedStatus: null })
    }
  }

  function update(changes: Partial<Draft>): void {
    if (draft.value === null) return
    draft.value = { ...draft.value, ...changes, updatedAt: Date.now() }
    if (saveTimer !== null) clearTimeout(saveTimer)
    saveTimer = setTimeout(() => void save(), AUTOSAVE_MS)
  }

  async function save(): Promise<void> {
    if (draft.value === null) return
    await mail.saveDraft(draft.value)
    savedAt.value = Date.now()
  }

  async function discard(): Promise<void> {
    if (draft.value === null) return
    if (saveTimer !== null) clearTimeout(saveTimer)
    await mail.deleteDraft(draft.value.id)
    draft.value = null
  }

  function close(): void {
    if (saveTimer !== null) clearTimeout(saveTimer)
    if (draft.value !== null) void save()
    draft.value = null
  }

  function validate(current: Draft): string | null {
    if (current.mailAccountId === null) return 'Choose the account to send from.'
    const recipients = [...current.to, ...current.cc, ...current.bcc]
    if (recipients.length === 0) return 'Add at least one recipient.'
    const wrong = recipients.find((recipient) => !isPlausibleAddress(recipient.address))
    if (wrong) return `${wrong.address} is not a valid address.`
    return null
  }

  /** Sends now (after the undo window) or at a chosen time. */
  async function send(sendAt?: Date): Promise<void> {
    const current = draft.value
    if (current === null || sending.value) return
    const problem = validate(current)
    if (problem) {
      error.value = problem
      return
    }
    sending.value = true
    error.value = null
    violations.value = {}
    const at = sendAt ?? new Date(Date.now() + UNDO_SECONDS * 1000)
    const request: SendMessageRequest = {
      mailAccountId: current.mailAccountId!,
      to: current.to,
      cc: current.cc,
      bcc: current.bcc,
      subject: current.subject,
      html: current.html,
      sendAt: at.toISOString(),
      ...(current.inReplyToMessageId ? { inReplyToMessageId: current.inReplyToMessageId } : {}),
    }
    try {
      const outgoing = await mail.send(request)
      await mail.saveDraft({ ...current, outgoingId: outgoing.id, updatedAt: Date.now() })
      draft.value = null
      if (sendAt === undefined) {
        startUndo(outgoing.id, current.id)
      } else {
        notify({ text: `Scheduled for ${at.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })}.`, tone: 'info' })
      }
    } catch (e) {
      error.value = errorMessage(e)
      violations.value = e instanceof ApiError ? e.violations : {}
    } finally {
      sending.value = false
    }
  }

  function startUndo(outgoingId: string, draftId: string): void {
    if (undoTimer !== null) clearTimeout(undoTimer)
    undoable.value = { outgoingId, draftId, until: Date.now() + UNDO_SECONDS * 1000 }
    undoTimer = setTimeout(() => (undoable.value = null), UNDO_SECONDS * 1000)
  }

  async function undo(): Promise<void> {
    const pending = undoable.value
    if (pending === null) return
    undoable.value = null
    if (undoTimer !== null) clearTimeout(undoTimer)
    try {
      await mail.cancelOutgoing(pending.outgoingId)
      await openDraft(pending.draftId)
    } catch (e) {
      notify({ text: `Too late to undo: ${errorMessage(e)}`, tone: 'error' })
    }
  }

  /** Follows every draft that was sent: confirmed ones are removed, failed ones offered for editing, once each. */
  async function followUp(): Promise<void> {
    for (const stored of await mail.drafts()) {
      if (stored.outgoingId === null) continue
      const outgoing = await mail.outgoing(stored.outgoingId)
      if (outgoing === null || outgoing.status === stored.noticedStatus) continue
      if (outgoing.status === 'sent') {
        await mail.deleteDraft(stored.id)
        if (outgoing.lastError) notify({ text: outgoing.lastError, tone: 'info' })
        continue
      }
      if (outgoing.status === 'failed') {
        notify(
          {
            text: `"${outgoing.subject || '(no subject)'}" was not sent. ${outgoing.lastError ?? ''}`.trim(),
            tone: 'error',
            action: { label: 'Edit and send again', run: () => void openDraft(stored.id) },
          },
          30000,
        )
      }
      await mail.saveDraft({ ...stored, noticedStatus: outgoing.status })
    }
  }

  /** The local draft behind an outgoing message, if it was written on this device. */
  async function draftFor(outgoingId: string): Promise<Draft | null> {
    return (await mail.drafts()).find((stored) => stored.outgoingId === outgoingId) ?? null
  }

  watch(() => mail.outbox, () => void followUp())

  return {
    draft,
    minimized,
    savedAt,
    sending,
    error,
    violations,
    notices,
    undoable,
    openNew,
    openReply,
    openDraft,
    update,
    discard,
    close,
    send,
    undo,
    dismiss,
    notify,
    followUp,
    draftFor,
  }
})
