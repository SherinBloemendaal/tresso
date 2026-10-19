import { isPlausibleAddress, type SendMessageRequest } from './compose'
import { defaultSignatureOf, newDraft, reopenedDraft, replyDraft, sendRequest, sentOutcome, type DraftSync } from './drafts'
import type { Draft, MailStore } from './store/mailStore'
import type { Address, MessageBody, SyncedMailAccount, SyncedMessage, SyncedOutgoingMessage, SyncedSignature } from './sync/types'

/** Seconds a sent message waits before it goes out, so "Undo" can still stop it. */
export const UNDO_SECONDS = 5
const AUTOSAVE_MS = 800
/** What the API accepts; the web form's schema (apps/web/src/forms/compose.ts) checks the same. */
export const MAX_RECIPIENTS = 100
export const MAX_SUBJECT = 998
export const MAX_HTML = 2_000_000

export interface ComposerNotice {
  text: string
  tone: 'info' | 'error'
  action?: { label: string; run: () => void }
}

export interface ComposerDeps {
  store(): MailStore | null
  /** The parts of DraftSync writing mail uses. */
  drafts: Pick<DraftSync, 'upload' | 'resolveConflict' | 'deleteEverywhere' | 'uploadForSending'>
  accounts(): SyncedMailAccount[]
  signatures(): SyncedSignature[]
  /** The account a new message is written from: the one on screen, or the first. */
  defaultAccount(): string | null
  body(messageId: string): Promise<Pick<MessageBody, 'html' | 'text'>>
  send(request: SendMessageRequest): Promise<SyncedOutgoingMessage>
  cancel(outgoingId: string): Promise<SyncedOutgoingMessage>
  notify(notice: ComposerNotice, ms: number): void
  /** What a failed request means for the user: its message, and messages per field. */
  explain(error: unknown): { message: string; violations: Record<string, string> }
  /** How a scheduled time is written in a notice. */
  formatTime?(at: Date): string
  undoSeconds?: number
  autosaveMs?: number
}

export interface ComposerState {
  draft: Draft | null
  /** Rises whenever a draft is (re)opened, so a form starts over even for the same draft. */
  revision: number
  /** When this device last saved the draft (epoch ms). */
  savedAt: number | null
  sending: boolean
  error: string | null
  violations: Record<string, string>
  /** Another device saved a newer version while this one was being written. */
  conflict: boolean
  /** A message that can still be stopped, until `until` (epoch ms). */
  undoable: { outgoingId: string; draftId: string; until: number } | null
}

/**
 * Writing mail, the same on every client: drafts are saved on the device while typing and uploaded, kept until the
 * message is confirmed sent (so a failure can be edited and sent again), and every message waits a few seconds so
 * "Undo" can stop it. Screens read `state` and are told of changes through `subscribe`.
 */
export class Composer {
  private current: ComposerState = { draft: null, revision: 0, savedAt: null, sending: false, error: null, violations: {}, conflict: false, undoable: null }
  private readonly listeners = new Set<() => void>()
  private saveTimer: ReturnType<typeof setTimeout> | null = null
  private undoTimer: ReturnType<typeof setTimeout> | null = null
  private following: Promise<void> | null = null
  private followAgain = false

  constructor(private readonly deps: ComposerDeps) {}

  get state(): ComposerState {
    return this.current
  }

  subscribe(listener: () => void): () => void {
    this.listeners.add(listener)
    return () => this.listeners.delete(listener)
  }

  private set(changes: Partial<ComposerState>): void {
    this.current = { ...this.current, ...changes }
    for (const listener of this.listeners) {
      listener()
    }
  }

  private get undoSeconds(): number {
    return this.deps.undoSeconds ?? UNDO_SECONDS
  }

  defaultSignature(mailAccountId: string | null): string | null {
    return defaultSignatureOf(this.deps.accounts(), this.deps.signatures(), mailAccountId)
  }

  private open(draft: Draft): void {
    this.set({ draft, revision: this.current.revision + 1, error: null, violations: {}, savedAt: null, conflict: draft.remoteVersion !== null })
  }

  openNew(to: Address[] = []): void {
    const account = this.deps.defaultAccount()
    this.open({ ...newDraft('new', account, this.defaultSignature(account)), to })
  }

  async openReply(message: SyncedMessage, kind: 'reply' | 'replyAll' | 'forward'): Promise<void> {
    const body = await this.deps.body(message.id).catch(() => ({ html: null, text: message.snippet }))
    this.open(replyDraft(message, body, kind, this.deps.accounts(), this.deps.signatures()))
  }

  async openDraft(id: string): Promise<void> {
    const stored = await this.deps.store()?.draft(id)
    if (stored) {
      this.open(reopenedDraft(stored))
    }
  }

  /** Changes the draft being written; it is saved and uploaded shortly after typing stops. */
  update(changes: Partial<Draft>): void {
    const draft = this.current.draft
    if (draft === null) {
      return
    }
    this.set({ draft: { ...draft, ...changes, pendingSave: true, updatedAt: Date.now() } })
    this.clearSaveTimer()
    this.saveTimer = setTimeout(() => void this.save(), this.deps.autosaveMs ?? AUTOSAVE_MS)
  }

  private clearSaveTimer(): void {
    if (this.saveTimer !== null) {
      clearTimeout(this.saveTimer)
      this.saveTimer = null
    }
  }

  /** Saves on this device at once and uploads, so the draft is on every device and in the Drafts folder. */
  async save(draft: Draft | null = this.current.draft): Promise<void> {
    const store = this.deps.store()
    if (draft === null || store === null) {
      return
    }
    const stored = await store.draft(draft.id)
    // Keep what the upload learned (server version, a conflict) and lay the edits on top.
    await store.saveDraft({ ...draft, version: stored?.version ?? draft.version, remoteVersion: stored?.remoteVersion ?? null })
    if (this.current.draft?.id === draft.id) {
      this.set({ savedAt: Date.now() })
    }
    try {
      await this.deps.drafts.upload(draft.id)
    } catch {
      // Offline or the server is unreachable: the edits wait on this device and go up later.
    }
    await this.checkConflict(draft.id)
  }

  private async checkConflict(id: string): Promise<void> {
    if (this.current.draft?.id !== id) {
      return
    }
    const remote = (await this.deps.store()?.draft(id))?.remoteVersion ?? null
    this.set({ conflict: remote !== null })
  }

  async resolveConflict(keep: 'mine' | 'theirs'): Promise<void> {
    const draft = this.current.draft
    if (draft === null) {
      return
    }
    await this.save()
    const resolved = await this.deps.drafts.resolveConflict(draft.id, keep)
    this.set({ conflict: false })
    if (keep === 'theirs' && resolved !== null) {
      // The form starts over with the other device's version.
      this.open(resolved)
    }
  }

  /** Throws the draft away, here and on the server. */
  async discard(): Promise<void> {
    const draft = this.current.draft
    if (draft === null) {
      return
    }
    this.clearSaveTimer()
    this.set({ draft: null, conflict: false })
    await this.deps.drafts.deleteEverywhere(draft.id)
  }

  /** Closes the form; the draft is kept. */
  close(): void {
    this.clearSaveTimer()
    const closing = this.current.draft
    this.set({ draft: null, conflict: false })
    void this.save(closing)
  }

  /** Why the draft cannot be sent as it is, or null when it can. */
  validate(draft: Draft): string | null {
    if (draft.mailAccountId === null) {
      return 'Choose the account to send from.'
    }
    const recipients = [...draft.to, ...draft.cc, ...draft.bcc]
    if (recipients.length === 0) {
      return 'Add at least one recipient.'
    }
    if (recipients.length > MAX_RECIPIENTS) {
      return `A message can have at most ${MAX_RECIPIENTS} recipients.`
    }
    const wrong = recipients.find((recipient) => !isPlausibleAddress(recipient.address))
    if (wrong) {
      return `${wrong.address} is not a valid address.`
    }
    if (draft.subject.length > MAX_SUBJECT || /[\r\n]/.test(draft.subject)) {
      return draft.subject.length > MAX_SUBJECT ? 'The subject is too long.' : 'The subject cannot contain line breaks.'
    }
    return draft.html.length > MAX_HTML ? 'The message is too long.' : null
  }

  /** Sends now (after the undo window) or at a chosen time. */
  async send(sendAt?: Date): Promise<void> {
    const draft = this.current.draft
    if (draft === null || this.current.sending) {
      return
    }
    const problem = this.validate(draft)
    if (problem) {
      this.set({ error: problem })
      return
    }
    this.set({ sending: true, error: null, violations: {} })
    const at = sendAt ?? new Date(Date.now() + this.undoSeconds * 1000)
    // The draft goes up first, so the server can take it (and its Drafts folder copy) away when the message is queued.
    this.clearSaveTimer()
    try {
      const onServer = await this.deps.drafts.uploadForSending(draft)
      const outgoing = await this.deps.send(sendRequest(draft, this.deps.signatures(), at, onServer))
      const store = this.deps.store()
      await store?.saveDraft({ ...((await store.draft(draft.id)) ?? draft), outgoingId: outgoing.id, pendingSave: false, updatedAt: Date.now() })
      this.set({ draft: null, conflict: false })
      if (sendAt === undefined) {
        this.startUndo(outgoing.id, draft.id)
      } else {
        const when = this.deps.formatTime?.(at) ?? at.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
        this.deps.notify({ text: `Scheduled for ${when}.`, tone: 'info' }, 8000)
      }
    } catch (error) {
      const { message, violations } = this.deps.explain(error)
      this.set({ error: message, violations })
    } finally {
      this.set({ sending: false })
    }
  }

  private startUndo(outgoingId: string, draftId: string): void {
    if (this.undoTimer !== null) {
      clearTimeout(this.undoTimer)
    }
    this.set({ undoable: { outgoingId, draftId, until: Date.now() + this.undoSeconds * 1000 } })
    this.undoTimer = setTimeout(() => this.set({ undoable: null }), this.undoSeconds * 1000)
  }

  /** Stops the message that was just sent and opens it again for editing. */
  async undo(): Promise<void> {
    const pending = this.current.undoable
    if (pending === null) {
      return
    }
    this.set({ undoable: null })
    if (this.undoTimer !== null) {
      clearTimeout(this.undoTimer)
    }
    try {
      await this.deps.cancel(pending.outgoingId)
      await this.openDraft(pending.draftId)
    } catch (error) {
      this.deps.notify({ text: `Too late to undo: ${this.deps.explain(error).message}`, tone: 'error' }, 8000)
    }
  }

  /**
   * Follows every draft that was sent: confirmed ones are removed, failed ones offered for editing, once each. Runs
   * never overlap, or two could announce the same outcome; a call during a run makes it go round once more.
   */
  followUp(): Promise<void> {
    this.followAgain = true
    if (this.following !== null) {
      return this.following
    }
    this.following = (async () => {
      try {
        while (this.followAgain) {
          this.followAgain = false
          await this.followUpOnce()
        }
      } finally {
        this.following = null
      }
    })()
    return this.following
  }

  private async followUpOnce(): Promise<void> {
    const store = this.deps.store()
    if (store === null) {
      return
    }
    for (const stored of await store.drafts()) {
      const outgoing = stored.outgoingId === null ? null : await store.outgoing(stored.outgoingId)
      const outcome = sentOutcome(stored, outgoing)
      if (outcome === null || outgoing === null) {
        continue
      }
      if (outcome === 'sent') {
        await store.deleteDraft(stored.id)
        if (outgoing.lastError) {
          this.deps.notify({ text: outgoing.lastError, tone: 'info' }, 8000)
        }
        continue
      }
      if (outcome === 'failed') {
        this.deps.notify(
          {
            text: `"${outgoing.subject || '(no subject)'}" was not sent. ${outgoing.lastError ?? ''}`.trim(),
            tone: 'error',
            action: { label: 'Edit and send again', run: () => void this.openDraft(stored.id) },
          },
          30000,
        )
      }
      await store.saveDraft({ ...stored, noticedStatus: outgoing.status })
    }
  }

  /** The local draft behind an outgoing message, if it was written on this device. */
  async draftFor(outgoingId: string): Promise<Draft | null> {
    return ((await this.deps.store()?.drafts()) ?? []).find((stored) => stored.outgoingId === outgoingId) ?? null
  }

  /** Stops the timers, e.g. on sign-out; a pending save is not made. */
  dispose(): void {
    this.clearSaveTimer()
    if (this.undoTimer !== null) {
      clearTimeout(this.undoTimer)
      this.undoTimer = null
    }
    this.listeners.clear()
  }
}
