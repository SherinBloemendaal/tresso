/** Wire format of GET /api/v1/sync/changes and GET /api/v1/realtime/token. */

export type FolderRole = 'inbox' | 'sent' | 'drafts' | 'trash' | 'spam' | 'archive' | 'all' | 'custom'

export type MailAccountStatus = 'active' | 'paused' | 'auth_failed' | 'error'

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
  categoryId: string | null
  verdict: 'do' | 'delegate' | 'know' | 'none' | null
  summary: string | null
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

export type DeletedType = 'mailAccount' | 'folder' | 'thread' | 'message' | 'outgoingMessage' | 'signature' | 'draft'

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

/** A draft attachment with its download link: signed, valid for an hour, null while quarantined. */
export interface DraftAttachmentLink extends DraftAttachment {
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
  deleted: Deleted[]
}

export interface RealtimeAccess {
  hubUrl: string
  topic: string
  token: string
  expiresAt: string
}

export type ScanStatus = 'pending' | 'clean' | 'infected' | 'failed'

export interface MessageAttachment {
  id: string
  filename: string
  contentType: string
  size: number
  inline: boolean
  contentId: string | null
  scanStatus: ScanStatus
  /** Signed, valid for an hour; null when quarantined. */
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
