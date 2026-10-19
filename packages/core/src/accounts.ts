/** Wire format of /api/v1/mail-accounts and /api/v1/mail-server-settings. */

import type { MailAccountStatus } from './sync/types'

export type ConnectionSecurity = 'tls' | 'starttls' | 'none'

export interface ServerSettings {
  host: string
  port: number
  security: ConnectionSecurity
}

export interface MailAccount {
  id: string
  emailAddress: string
  displayName: string
  provider: string
  status: MailAccountStatus
  statusDetail: string | null
  username: string
  imap: ServerSettings
  smtp: ServerSettings
  lastSyncedAt: string | null
  createdAt: string
  /** Changed through the signature's `defaultFor`. */
  defaultSignatureId: string | null
}

export interface NewMailAccount {
  emailAddress: string
  displayName: string
  username: string
  password: string
  imap: ServerSettings
  smtp: ServerSettings
}

/** JSON merge patch: omitted fields stay as they are. A new password also clears a sign-in failure. */
export interface MailAccountUpdate {
  displayName?: string
  username?: string
  password?: string
  imap?: ServerSettings
  smtp?: ServerSettings
  status?: 'active' | 'paused'
}

export type SignInMethod = 'password' | 'oauth2'

export interface SuggestedServerSettings {
  emailAddress: string
  found: boolean
  source: string | null
  username: string | null
  imap: ServerSettings | null
  smtp: ServerSettings | null
  authentication: SignInMethod[]
}

/** The usual ports, to fill in when the user picks a security mode. */
export function defaultPort(protocol: 'imap' | 'smtp', security: ConnectionSecurity): number {
  if (protocol === 'imap') {
    return security === 'tls' ? 993 : 143
  }
  return security === 'tls' ? 465 : 587
}
