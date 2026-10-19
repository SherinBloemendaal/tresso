import type { SyncedDraft, SyncedMessage } from '../sync/types'
import type { Arrival, Contact, Draft } from './mailStore'

function needsTheUser(message: SyncedMessage | null | undefined): boolean {
  return message?.verdict === 'do' || message?.verdict === 'delegate'
}

/**
 * The arrival a stored message makes, if it is one: unread, in an inbox, and judged `do` or `delegate` for the first
 * time (when it arrived, or when it was classified later). Flag changes on a message judged before do not repeat it.
 */
export function arrivalOf(previous: SyncedMessage | null | undefined, message: SyncedMessage, inboxFolders: Set<string>): Arrival | null {
  if (
    (message.verdict !== 'do' && message.verdict !== 'delegate') ||
    needsTheUser(previous) ||
    message.flags.seen ||
    !message.folderIds.some((id) => inboxFolders.has(id))
  ) {
    return null
  }
  return {
    messageId: message.id,
    threadId: message.threadId,
    mailAccountId: message.mailAccountId,
    from: message.from,
    subject: message.subject,
    snippet: message.snippet,
    receivedAt: message.receivedAt,
    verdict: message.verdict,
  }
}

/** Everyone on a new message is a suggestion, under a lower-cased address; addresses without an @ are left out. */
export function peopleOn(message: SyncedMessage): { key: string; person: SyncedMessage['from'] }[] {
  const people = [message.from, ...message.recipients.to, ...message.recipients.cc]
  return people.map((person) => ({ key: person.address.trim().toLowerCase(), person })).filter(({ key }) => key !== '' && key.includes('@'))
}

/** A contact after one more message with them: the newest name and spelling win. */
export function mergeContact(known: Contact | null | undefined, key: string, person: SyncedMessage['from'], at: number): Contact {
  const newer = known == null || at >= known.lastSeen
  return {
    key,
    address: newer ? person.address : known.address,
    name: (newer ? person.name : known.name) ?? known?.name ?? null,
    lastSeen: Math.max(at, known?.lastSeen ?? 0),
    messages: (known?.messages ?? 0) + 1,
  }
}

/** Whether a contact matches what the user typed: the start of its address or of a word of its name. */
export function contactMatches(contact: Contact, query: string): boolean {
  const needle = query.trim().toLowerCase()
  const words = [contact.key, ...(contact.name ?? '').toLowerCase().split(/\s+/)]
  return needle === '' || words.some((word) => word.startsWith(needle))
}

/**
 * What a draft from the server makes of the one on this device. Unsaved edits are never overwritten: when the server
 * moved past the version they build on, the newer version is noted for the user to settle. Returns null when the
 * device's draft stays as it is.
 */
export function syncedDraft(local: Draft | null | undefined, draft: SyncedDraft): Draft | null {
  if (local?.pendingSave) {
    // The text waits for its upload; the files are the server's either way.
    const remoteVersion = draft.version > local.version ? draft.version : local.remoteVersion
    const attachments = draft.attachments ?? []
    if (remoteVersion === local.remoteVersion && JSON.stringify(attachments) === JSON.stringify(local.attachments ?? [])) {
      return null
    }
    return { ...local, remoteVersion, attachments }
  }
  return {
    ...draft,
    updatedAt: Date.parse(draft.updatedAt),
    pendingSave: false,
    remoteVersion: null,
    outgoingId: local?.outgoingId ?? null,
    noticedStatus: local?.noticedStatus ?? null,
    attachments: draft.attachments ?? [],
  }
}

/**
 * What a draft the server deleted leaves on this device: a sent one stays until its outcome is known (the server keeps
 * it meanwhile, version and files, so an undo or a failure brings it back); unsaved edits stay too. Returns the draft
 * to keep, or null to delete it.
 */
export function deletedDraft(local: Draft): Draft | null {
  if (local.outgoingId) {
    return { ...local, pendingSave: false, remoteVersion: null }
  }
  return local.pendingSave ? local : null
}

/** A draft as stored by an earlier version of the app, with what it did not know yet. */
export function storedDraft(draft: Draft): Draft {
  return draft.attachments === undefined ? { ...draft, attachments: [] } : draft
}

/** Whether a draft holds something that exists nowhere else: edits not uploaded, or a message whose outcome is pending. */
export function draftOnlyHere(draft: Draft): boolean {
  return draft.pendingSave || draft.outgoingId !== null
}
