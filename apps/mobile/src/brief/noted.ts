import { undoPlan, type AppliedAction, type MessageAction, type SyncedFolder, type UndoMove } from '@tresso/core'
import type { Notice } from '@/components/Notices'

/** What noting needs from the screen: the copy, the message actions (errors already shown) and the notices. */
export interface NotedDeps {
  messageIdsOfThreads(threadIds: string[]): Promise<string[]>
  folders(): Promise<SyncedFolder[]>
  /** An action whose errors become notices; null when nothing changed. */
  run(messageIds: string[], action: MessageAction): Promise<AppliedAction | null>
  act(messageIds: string[], action: MessageAction, target?: { folderId?: string; fromFolderId?: string }): Promise<AppliedAction>
  notify(notice: Notice): void
}

function errorText(error: unknown): string {
  return error instanceof Error ? error.message : String(error)
}

/** Puts noted messages back: out of the archive into the folder they came from, and unread where they were. */
export async function restoreNoted(deps: NotedDeps, moves: UndoMove[], unread: string[]): Promise<void> {
  let failed = 0
  let reason = ''
  for (const move of moves) {
    try {
      await deps.act(move.messageIds, 'move', { folderId: move.folderId, fromFolderId: move.fromFolderId })
    } catch (error) {
      failed += move.messageIds.length
      reason = errorText(error)
    }
  }
  if (unread.length > 0) {
    try {
      await deps.act(unread, 'markUnread')
    } catch (error) {
      failed += unread.length
      reason = errorText(error)
    }
  }
  if (failed > 0) {
    deps.notify({ text: `${failed === 1 ? 'One message' : `${failed} messages`} could not be put back: ${reason}`, tone: 'error' })
  }
}

/**
 * "Noted", the same as on the web: the conversations are marked read and archived through the message actions, and
 * the notice offers an undo. When archiving fails, what was unread is unread again, so nothing is half noted.
 */
export async function noteThreads(deps: NotedDeps, threadIds: string[]): Promise<void> {
  if (threadIds.length === 0) {
    return
  }
  const messageIds = await deps.messageIdsOfThreads(threadIds)
  const read = await deps.run(messageIds, 'markRead')
  if (read === null) {
    return
  }
  const unread = read.before.filter((message) => !message.flags.seen).map((message) => message.id)
  const archived = await deps.run(messageIds, 'archive')
  if (archived === null) {
    await restoreNoted(deps, [], unread)
    return
  }
  const plan = undoPlan(archived, 'archive', await deps.folders())
  deps.notify({
    text: threadIds.length === 1 ? 'Noted: read and archived.' : `${threadIds.length} conversations noted: read and archived.`,
    tone: 'info',
    action: plan.moves.length > 0 ? { label: 'Undo', accessibilityLabel: 'Undo noted', run: () => void restoreNoted(deps, plan.moves, unread) } : undefined,
  })
}
