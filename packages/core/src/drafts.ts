import { composeBody, forwardSubject, quotedOriginal, replyRecipients, replySubject, type DraftRequest, type SendMessageRequest } from './compose'
import type { Draft, MailStore } from './store/mailStore'
import type { DraftKind, MessageBody, SyncedDraft, SyncedMailAccount, SyncedMessage, SyncedOutgoingMessage, SyncedSignature } from './sync/types'

/** The drafts API. Errors carry the HTTP status as `status`, as both clients' API errors do. */
export interface DraftApi {
  create(id: string, request: DraftRequest): Promise<SyncedDraft>
  update(id: string, request: DraftRequest, expectedVersion: number): Promise<SyncedDraft>
  get(id: string): Promise<SyncedDraft>
  remove(id: string): Promise<void>
}

function statusOf(error: unknown): number | null {
  const status = (error as { status?: unknown } | null)?.status
  return typeof status === 'number' ? status : null
}

/** A new, empty draft on this device. */
export function newDraft(kind: DraftKind, mailAccountId: string | null, signatureId: string | null, id: string = crypto.randomUUID()): Draft {
  return {
    id,
    mailAccountId,
    to: [],
    cc: [],
    bcc: [],
    subject: '',
    html: '',
    signatureId,
    quoteHtml: '',
    inReplyToMessageId: null,
    kind,
    version: 0,
    pendingSave: true,
    remoteVersion: null,
    outgoingId: null,
    noticedStatus: null,
    updatedAt: Date.now(),
  }
}

/** The signature an account adds by default, if it still exists. */
export function defaultSignatureOf(accounts: SyncedMailAccount[], signatures: SyncedSignature[], mailAccountId: string | null): string | null {
  const id = accounts.find((account) => account.id === mailAccountId)?.defaultSignatureId ?? null
  return signatures.some((signature) => signature.id === id) ? id : null
}

/**
 * A reply, reply to all or forward of a message: recipients without the user's own addresses, the subject, and the
 * quoted original (kept apart from what the user writes).
 */
export function replyDraft(
  message: SyncedMessage,
  body: Pick<MessageBody, 'html' | 'text'>,
  kind: 'reply' | 'replyAll' | 'forward',
  accounts: SyncedMailAccount[],
  signatures: SyncedSignature[],
): Draft {
  const draft = newDraft(kind, message.mailAccountId, defaultSignatureOf(accounts, signatures, message.mailAccountId))
  draft.inReplyToMessageId = message.id
  if (kind === 'forward') {
    draft.subject = forwardSubject(message.subject)
  } else {
    const recipients = replyRecipients(
      message,
      accounts.map((account) => account.emailAddress),
      kind === 'replyAll',
    )
    draft.to = recipients.to
    draft.cc = recipients.cc
    draft.subject = replySubject(message.subject)
  }
  draft.quoteHtml = quotedOriginal(message, body, kind === 'forward' ? 'forward' : 'reply')
  return draft
}

/** What the API needs to send a draft: the text with its signature and quote, at a time (with the undo window ahead). */
export function sendRequest(draft: Draft, signatures: SyncedSignature[], sendAt: Date, onServer: boolean): SendMessageRequest {
  if (draft.mailAccountId === null) {
    throw new Error('Choose the account to send from.')
  }
  return {
    mailAccountId: draft.mailAccountId,
    to: draft.to,
    cc: draft.cc,
    bcc: draft.bcc,
    subject: draft.subject,
    html: composeBody(draft.html, signatures.find((signature) => signature.id === draft.signatureId)?.html ?? null, draft.quoteHtml),
    sendAt: sendAt.toISOString(),
    ...(draft.inReplyToMessageId ? { inReplyToMessageId: draft.inReplyToMessageId } : {}),
    ...(onServer ? { draftId: draft.id } : {}),
  }
}

/**
 * What became of a sent draft that the user was not told about yet: `sent` (the draft can go), `failed` (offer to edit
 * and send again), `other` (a status to remember without telling), or null when nothing changed.
 */
export function sentOutcome(draft: Draft, outgoing: SyncedOutgoingMessage | null): 'sent' | 'failed' | 'other' | null {
  if (draft.outgoingId === null || outgoing === null || outgoing.status === draft.noticedStatus) {
    return null
  }
  return outgoing.status === 'sent' ? 'sent' : outgoing.status === 'failed' ? 'failed' : 'other'
}

/** Editing a message that failed to send makes it a draft again. */
export function reopenedDraft(draft: Draft): Draft {
  return draft.outgoingId === null ? draft : { ...draft, outgoingId: null, noticedStatus: null, pendingSave: true }
}

function requestOf(draft: Draft): DraftRequest {
  return {
    mailAccountId: draft.mailAccountId,
    kind: draft.kind,
    to: draft.to,
    cc: draft.cc,
    bcc: draft.bcc,
    subject: draft.subject,
    html: draft.html,
    signatureId: draft.signatureId,
    quoteHtml: draft.quoteHtml,
    inReplyToMessageId: draft.inReplyToMessageId,
  }
}

/**
 * Keeps the drafts written on this device in step with the server: edits saved locally go up, one upload per draft at
 * a time, and another device's newer version is never overwritten but noted on the draft for the user to settle.
 * Shared by the web, desktop and mobile clients.
 */
export class DraftSync {
  private readonly uploads = new Map<string, Promise<void>>()
  private readonly again = new Set<string>()

  constructor(
    private readonly store: () => MailStore | null,
    private readonly api: DraftApi,
    /** Called when a conflict was noted, so screens can show it. */
    private readonly onConflict: () => void = () => {},
  ) {}

  /**
   * Brings the draft's unsaved edits to the server. Uploads of one draft never overlap: an upload asked for during one
   * runs right after it, with whatever was saved by then.
   */
  upload(id: string): Promise<void> {
    this.again.add(id)
    const running = this.uploads.get(id)
    if (running) {
      return running
    }
    const run = (async () => {
      try {
        while (this.again.delete(id)) {
          await this.uploadOnce(id)
        }
      } finally {
        this.uploads.delete(id)
      }
    })()
    this.uploads.set(id, run)
    return run
  }

  private async uploadOnce(id: string): Promise<void> {
    const store = this.store()
    const local = await store?.draft(id)
    if (!store || !local || !local.pendingSave || local.remoteVersion !== null || local.outgoingId !== null) {
      return
    }
    let saved: SyncedDraft
    try {
      saved = local.version === 0 ? await this.api.create(id, requestOf(local)) : await this.api.update(id, requestOf(local), local.version)
    } catch (error) {
      const status = statusOf(error)
      if (status === 409 && local.version > 0) {
        const remote = await this.api.get(id)
        await store.saveDraft({ ...((await store.draft(id)) ?? local), remoteVersion: remote.version })
        this.onConflict()
        return
      }
      if (status === 404 && local.version > 0) {
        // Deleted on another device while it was being written here: it becomes a new draft again.
        await store.saveDraft({ ...((await store.draft(id)) ?? local), version: 0 })
        this.again.add(id)
        return
      }
      throw error
    }
    // Edits made while the upload was under way stay pending and go up in the next round.
    const current = (await store.draft(id)) ?? local
    const unchanged = current.updatedAt === local.updatedAt
    await store.saveDraft({ ...current, version: saved.version, pendingSave: !unchanged })
    if (!unchanged) {
      this.again.add(id)
    }
  }

  /** Uploads every draft with edits that did not reach the server yet, e.g. after starting offline. */
  async uploadPending(): Promise<void> {
    for (const draft of (await this.store()?.drafts()) ?? []) {
      if (draft.pendingSave && draft.remoteVersion === null && draft.outgoingId === null) {
        await this.upload(draft.id).catch(() => undefined)
      }
    }
  }

  /** Settles a conflict: keep the edits made here (they overwrite the other version) or take the other version. */
  async resolveConflict(id: string, keep: 'mine' | 'theirs'): Promise<Draft | null> {
    const store = this.store()
    const local = await store?.draft(id)
    if (!store || !local || local.remoteVersion === null) {
      return local ?? null
    }
    const remote = await this.api.get(id)
    const next: Draft =
      keep === 'mine'
        ? { ...local, version: remote.version, remoteVersion: null, pendingSave: true }
        : { ...remote, updatedAt: Date.parse(remote.updatedAt), pendingSave: false, remoteVersion: null, outgoingId: null, noticedStatus: null }
    await store.saveDraft(next)
    if (keep === 'mine') {
      await this.upload(id)
    }
    return (await store.draft(id)) ?? next
  }

  /** Removes the draft here and on the server, and with it its copy in the Drafts folder. */
  async deleteEverywhere(id: string): Promise<void> {
    const store = this.store()
    const local = await store?.draft(id)
    if (local && local.version > 0) {
      try {
        await this.api.remove(id)
      } catch (error) {
        if (statusOf(error) !== 404) {
          throw error
        }
      }
    }
    await store?.deleteDraft(id)
  }

  /** Saves the draft and uploads it, reporting whether the server has it (so sending can take its Drafts copy away). */
  async uploadForSending(draft: Draft): Promise<boolean> {
    const store = this.store()
    if (!store) {
      return false
    }
    await store.saveDraft(draft)
    return this.upload(draft.id)
      .then(async () => ((await store.draft(draft.id))?.version ?? 0) > 0)
      .catch(() => false)
  }
}
