/** Wire format of GET /api/v1/sync/changes and GET /api/v1/realtime/token. */

import type { BuiltinCategory, Verdict } from '../domain'
import type { SyncedCalendar, SyncedCalendarEvent } from '../calendar/types'
import type { SyncedEventNote, SyncedMailNotification, SyncedNotification } from '../notifications'

export type FolderRole = 'inbox' | 'sent' | 'drafts' | 'trash' | 'spam' | 'archive' | 'all' | 'custom'

export type MailAccountStatus = 'active' | 'paused' | 'auth_failed' | 'error'

/** How syncing an account's calendars goes, apart from its mail. */
export type CalendarSyncStatus = 'unknown' | 'active' | 'unsupported' | 'auth_failed' | 'unconfirmed' | 'error'

export interface Address {
  address: string
  name: string | null
}

export interface Recipients {
  to: Address[]
  cc: Address[]
  bcc: Address[]
  replyTo: Address[]
}

export interface SyncedMailAccount {
  id: string
  emailAddress: string
  displayName: string
  provider: string
  status: MailAccountStatus
  statusDetail: string | null
  lastSyncedAt: string | null
  /** The signature inserted into new messages from this account. */
  defaultSignatureId: string | null
  calendarStatus: CalendarSyncStatus
  calendarStatusDetail: string | null
}

export interface SyncedFolder {
  id: string
  mailAccountId: string
  name: string
  displayName: string
  role: FolderRole
}

export interface SyncedThread {
  id: string
  mailAccountId: string
  subject: string
  messageCount: number
  lastMessageAt: string
  /** The smart inbox the conversation is in; null until its first incoming message was classified. */
  smartInboxId: string | null
  verdict: Verdict | null
  /** Why it is there (see `ClassificationReason`); `user` when the user put it there. */
  classificationReason: string | null
}

export interface MessageFlags {
  seen: boolean
  flagged: boolean
  answered: boolean
  draft: boolean
}

export interface SyncedMessage {
  id: string
  mailAccountId: string
  threadId: string
  folderIds: string[]
  subject: string
  from: Address
  recipients: Recipients
  snippet: string
  sentAt: string | null
  receivedAt: string
  flags: MessageFlags
  hasAttachments: boolean
  /**
   * All attachments together (inline ones included): 'infected' as soon as one is blocked, then 'pending' while any is
   * still being scanned, then 'failed', else 'clean'. Null without attachments.
   */
  attachmentScan: ScanStatus | null
  /** The smart inbox and verdict this message got; the conversation takes those of its newest incoming message. */
  smartInboxId: string | null
  verdict: Verdict | null
  summary: string | null
  /** The iTIP method of the calendar object the message carries (REQUEST, CANCEL, REPLY, ...), or null. */
  calendarMethod: string | null
}


export type OutgoingStatus = 'scheduled' | 'queued' | 'sending' | 'sent' | 'failed' | 'cancelled'

export interface OutgoingRecipients {
  to: Address[]
  cc: Address[]
  bcc: Address[]
}

/** Mail on its way out; `lastError` explains a retry or failure, or a sent message whose Sent copy is missing. */
export interface SyncedOutgoingMessage {
  id: string
  mailAccountId: string
  status: OutgoingStatus
  subject: string
  recipients: OutgoingRecipients
  sendAt: string
  sentAt: string | null
  lastError: string | null
  inReplyToMessageId: string | null
}
/** Sanitized HTML; render only in a sandboxed frame (see `signatureFrameDocument`). */
export interface SyncedSignature {
  id: string
  name: string
  html: string
  updatedAt: string
}

/** A smart inbox: one of the five built-in ones, or one of the user's own (`builtin` null). */
export interface SyncedSmartInbox {
  id: string
  builtin: BuiltinCategory | null
  name: string
  /** What belongs here in plain words, for AI classification (an add-on). */
  instructions: string
  /** Addresses or `@domain`s whose mail always lands here. */
  includeSenders: string[]
  /** Addresses or `@domain`s whose mail never lands here; wins over everything else. */
  excludeSenders: string[]
  verdict: Verdict
  /** New unread mail judged `do` here becomes a todo; only with the `do` verdict. */
  autoCreateTodo: boolean
  /** Sidebar order. */
  position: number
}

/** Who made a todo: the user, the user accepting a suggestion, or a smart inbox on its own. */
export type TodoSource = 'manual' | 'suggested' | 'automatic'

/**
 * Something the user has to do. The due date and time are wall-clock values in the user's own time zone. `messageId`
 * and `threadId` point at the email it came from; a link to an email this device does not have counts as none.
 */
export interface SyncedTodo {
  id: string
  title: string
  notes: string
  /** YYYY-MM-DD, or null. */
  dueDate: string | null
  /** HH:MM, only with a due date; null means some time that day. */
  dueTime: string | null
  durationMinutes: number | null
  /** When it was done; null while open. */
  completedAt: string | null
  /** The place among the open todos; lower comes first. */
  position: number
  source: TodoSource
  messageId: string | null
  threadId: string | null
  createdAt: string
  updatedAt: string
}

export type DeletedType =
  | 'mailAccount'
  | 'folder'
  | 'thread'
  | 'message'
  | 'outgoingMessage'
  | 'signature'
  | 'draft'
  | 'smartInbox'
  | 'calendar'
  | 'calendarEvent'
  | 'notification'
  | 'eventNote'
  | 'todo'

export type DraftKind = 'new' | 'reply' | 'replyAll' | 'forward'

/** A message being written, the same on every device. `version` rises with every save. */
/** A file attached to a draft. The delta carries it without a link; the draft's own endpoint has a fresh one. */
export interface DraftAttachment {
  id: string
  filename: string
  contentType: string
  size: number
  scanStatus: ScanStatus
  /** Shown inside the text as cid:{contentId} (an image of a quoted original) rather than listed as a file. */
  inline?: boolean
  contentId?: string | null
}

/** A draft attachment with its download link: signed, valid for an hour, only when `access` is `allowed`. */
export interface DraftAttachmentLink extends DraftAttachment {
  access: AttachmentAccess
  url: string | null
  /** The draft's version with this file, in the answer to adding it. */
  draftVersion?: number | null
}

export interface SyncedDraft {
  id: string
  mailAccountId: string | null
  kind: DraftKind
  to: Address[]
  cc: Address[]
  bcc: Address[]
  subject: string
  /** What the user writes, without signature and quote. */
  html: string
  signatureId: string | null
  quoteHtml: string
  inReplyToMessageId: string | null
  version: number
  updatedAt: string
  /** Kept by the server: added and removed through their own endpoints, never with a draft save. */
  attachments: DraftAttachment[]
}

export interface Deleted {
  type: DeletedType
  id: string
}

export interface ChangePage {
  cursor: number
  hasMore: boolean
  mailAccounts: SyncedMailAccount[]
  folders: SyncedFolder[]
  threads: SyncedThread[]
  messages: SyncedMessage[]
  outgoingMessages: SyncedOutgoingMessage[]
  signatures: SyncedSignature[]
  drafts: SyncedDraft[]
  smartInboxes: SyncedSmartInbox[]
  calendars: SyncedCalendar[]
  calendarEvents: SyncedCalendarEvent[]
  notifications: SyncedNotification[]
  eventNotes: SyncedEventNote[]
  todos: SyncedTodo[]
  mailNotifications: SyncedMailNotification[]
  deleted: Deleted[]
}

export interface RealtimeAccess {
  hubUrl: string
  topic: string
  token: string
  expiresAt: string
}

export type ScanStatus = 'pending' | 'clean' | 'infected' | 'failed'

/** Why a scan failed: too large for the scanner, unreadable for it, scanner out of reach, or scanning turned off. */
export type ScanFailure = 'too_large' | 'unscannable' | 'unavailable' | 'disabled'

/**
 * What the scan allows: `allowed` (clean, with a link), `scanning`, `confirm` (could not be scanned: a link only after
 * the user accepts that, from the `unscanned-link` endpoints) or `blocked` (malware, or not scanned on a server that
 * requires scans).
 */
export type AttachmentAccess = 'allowed' | 'scanning' | 'confirm' | 'blocked'

export interface MessageAttachment {
  id: string
  filename: string
  contentType: string
  size: number
  inline: boolean
  contentId: string | null
  scanStatus: ScanStatus
  access: AttachmentAccess
  /** The malware found, when infected. */
  scanSignature: string | null
  /** Why the scan failed, when it did. */
  scanFailure: ScanFailure | null
  /** Signed, valid for an hour; only for `allowed`. */
  url: string | null
}

/** GET /api/v1/messages/{id}/body */
export interface MessageBody {
  id: string
  /** Sanitized; render only in a sandboxed frame (see `mailFrameDocument`). */
  html: string | null
  text: string | null
  hasRemoteContent: boolean
  remoteContentLoaded: boolean
  attachments: MessageAttachment[]
}
