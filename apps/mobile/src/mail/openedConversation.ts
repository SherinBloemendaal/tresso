import type { SyncState } from './mailSession'

/** What a conversation screen shows while the conversation is not in the copy. */
export type AbsentConversation = 'loading' | 'unreachable' | 'missing'

/**
 * A conversation not in the copy may not have arrived yet (a tapped notification can be quicker than the delta): it
 * is loading until a pull completes after it opened, and then known to be gone; when the pull fails (offline), it
 * could not be loaded.
 */
export function absentConversation(pulls: number, pullsAtOpen: number, syncState: SyncState): AbsentConversation {
  if (pulls > pullsAtOpen) {
    return 'missing'
  }
  return syncState === 'error' ? 'unreachable' : 'loading'
}
