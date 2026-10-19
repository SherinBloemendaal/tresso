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

export type DeletedType = 'mailAccount' | 'folder' | 'thread' | 'message'

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
