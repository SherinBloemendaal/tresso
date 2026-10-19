import { ref, watch } from 'vue'
import { defineStore } from 'pinia'
import { defaultSignatureOf, isPlausibleAddress, newDraft, reopenedDraft, replyDraft, sendRequest, sentOutcome, type Address, type SyncedMessage } from '@tresso/core'
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

  /** The signature an account adds by default. */
  function defaultSignature(mailAccountId: string | null): string | null {
    return defaultSignatureOf(mail.accounts, mail.signatures, mailAccountId)
  }

  /** Rises whenever a draft is (re)opened, so the form starts over even for the same draft. */
  const revision = ref(0)

  function open(next: Draft): void {
    revision.value++
    draft.value = next
    minimized.value = false
    error.value = null
    violations.value = {}
    savedAt.value = null
  }

  function openNew(to: Address[] = []): void {
    const account = defaultAccount()
    open({ ...newDraft('new', account, defaultSignature(account)), to })
  }

  async function openReply(message: SyncedMessage, kind: 'reply' | 'replyAll' | 'forward'): Promise<void> {
    const body = await mail.body(message.id, false).catch(() => ({ html: null, text: message.snippet }))
    open(replyDraft(message, body, kind, mail.accounts, mail.signatures))
  }

  async function openDraft(id: string): Promise<void> {
    const stored = await mail.draft(id)
    if (stored) {
      open(reopenedDraft(stored))
    }
  }

  function update(changes: Partial<Draft>): void {
    if (draft.value === null) return
    draft.value = { ...draft.value, ...changes, pendingSave: true, updatedAt: Date.now() }
    if (saveTimer !== null) clearTimeout(saveTimer)
    saveTimer = setTimeout(() => void save(), AUTOSAVE_MS)
  }

  /** Saves on this device at once and uploads, so the draft is on every device and in the Drafts folder. */
  async function save(current: Draft | null = draft.value): Promise<void> {
    if (current === null) return
    const stored = await mail.draft(current.id)
    // Keep what the upload learned (server version, a conflict) and lay the edits on top.
    await mail.saveDraft({ ...current, version: stored?.version ?? current.version, remoteVersion: stored?.remoteVersion ?? null })
    savedAt.value = Date.now()
    try {
      await mail.uploadDraft(current.id)
    } catch {
      // Offline or the server is unreachable: the edits wait on this device and go up later.
    }
    await checkConflict(current.id)
  }

  /** Another device saved a newer version while this one was being written. */
  const conflict = ref(false)

  async function checkConflict(id: string): Promise<void> {
    if (draft.value?.id !== id) return
    conflict.value = ((await mail.draft(id))?.remoteVersion ?? null) !== null
  }

  async function resolveConflict(keep: 'mine' | 'theirs'): Promise<void> {
    const current = draft.value
    if (current === null) return
    await save()
    const resolved = await mail.resolveDraftConflict(current.id, keep)
    conflict.value = false
    if (keep === 'theirs' && resolved !== null) {
      // The form starts over with the other device's version.
      draft.value = null
      open(resolved)
    }
  }

  async function discard(): Promise<void> {
    if (draft.value === null) return
    if (saveTimer !== null) clearTimeout(saveTimer)
    const id = draft.value.id
    draft.value = null
    await mail.deleteDraftEverywhere(id)
  }

  function close(): void {
    if (saveTimer !== null) clearTimeout(saveTimer)
    const closing = draft.value
    draft.value = null
    conflict.value = false
    void save(closing)
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
    // The draft goes up first, so the server can take it (and its Drafts folder copy) away when the message is queued.
    if (saveTimer !== null) clearTimeout(saveTimer)
    const onServer = await mail.uploadDraftForSending(current)
    try {
      const outgoing = await mail.send(sendRequest(current, mail.signatures, at, onServer))
      await mail.saveDraft({ ...((await mail.draft(current.id)) ?? current), outgoingId: outgoing.id, pendingSave: false, updatedAt: Date.now() })
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

  let following: Promise<void> | null = null
  let followAgain = false

  /**
   * Follows every draft that was sent: confirmed ones are removed, failed ones offered for editing, once each. Runs
   * never overlap, or two could announce the same outcome; a call during a run makes it go round once more.
   */
  function followUp(): Promise<void> {
    followAgain = true
    if (following !== null) return following
    following = (async () => {
      try {
        while (followAgain) {
          followAgain = false
          await followUpOnce()
        }
      } finally {
        following = null
      }
    })()
    return following
  }

  async function followUpOnce(): Promise<void> {
    for (const stored of await mail.drafts()) {
      const outgoing = stored.outgoingId === null ? null : await mail.outgoing(stored.outgoingId)
      const outcome = sentOutcome(stored, outgoing)
      if (outcome === null || outgoing === null) continue
      if (outcome === 'sent') {
        await mail.deleteDraft(stored.id)
        if (outgoing.lastError) notify({ text: outgoing.lastError, tone: 'info' })
        continue
      }
      if (outcome === 'failed') {
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
    revision,
    conflict,
    resolveConflict,
    minimized,
    savedAt,
    sending,
    error,
    violations,
    notices,
    undoable,
    openNew,
    openReply,
    defaultSignature,
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
