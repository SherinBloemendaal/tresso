/**
 * What the user does to messages: read, flag, archive, trash, move. `POST /api/v1/message-actions` makes the change
 * at once and the account's sync worker makes it on the mail server.
 */
export type MessageAction = 'markRead' | 'markUnread' | 'flag' | 'unflag' | 'archive' | 'trash' | 'move'

export interface MessageActionRequest {
  messageIds: string[]
  action: MessageAction
  /** The folder to move into; only for `move`. */
  folderId?: string
}

export interface MessageActionResult {
  /** How many of the messages changed; the others already were that way. */
  changed: number
}

/** Most messages one request takes. */
export const MAX_MESSAGES_PER_ACTION = 500
