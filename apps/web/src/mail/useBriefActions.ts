import { ref } from 'vue'
import { undoPlan, type UndoMove } from '@tresso/core'
import { errorMessage } from '@/api/errors'
import { useMessageActions } from '@/mail/useMessageActions'
import { useComposeStore } from '@/stores/compose'
import { useMailStore } from '@/stores/mail'

/** What puts a noted conversation back: the moves out of the archive and the messages that were unread. */
interface NotedUndo {
  moves: UndoMove[]
  unread: string[]
}

/**
 * "Noted" as the brief uses it: conversations are marked read and archived through the message actions, with an undo
 * that puts back where they were and what was unread. "Needs me" moves one to To do instead.
 */
export function useBriefActions() {
  const mail = useMailStore()
  const compose = useComposeStore()
  const { busy, run, exclusive } = useMessageActions()
  /** Conversations noted on this screen, with how to put each back. */
  const undoable = ref(new Map<string, NotedUndo>())

  function note(threadIds: string[]): Promise<void> {
    return exclusive(async () => {
      const byThread = await mail.messagesByThread(threadIds)
      const messageIds = [...byThread.values()].flat()
      if (messageIds.length === 0) {
        return
      }
      const read = await run(messageIds, 'markRead')
      if (read === null) {
        return
      }
      const unread = read.before.filter((message) => !message.flags.seen).map((message) => message.id)
      const archived = await run(messageIds, 'archive')
      if (archived === null) {
        // Archiving failed (the notice says why): what was unread is unread again, so nothing is half noted.
        await restore({ moves: [], unread })
        return
      }
      const plan = undoPlan(archived, 'archive', mail.allFolders)
      const next = new Map(undoable.value)
      for (const [threadId, ids] of byThread) {
        const own = new Set(ids)
        next.set(threadId, {
          moves: plan.moves.map((move) => ({ ...move, messageIds: move.messageIds.filter((id) => own.has(id)) })).filter((move) => move.messageIds.length > 0),
          unread: unread.filter((id) => own.has(id)),
        })
      }
      undoable.value = next
      const count = threadIds.length
      compose.notify({
        text: count === 1 ? 'Noted: read and archived.' : `${count} conversations noted: read and archived.`,
        tone: 'info',
        action: plan.moves.length > 0 ? { label: 'Undo', run: () => void undo(threadIds) } : undefined,
      })
    })
  }

  /** Puts noted conversations back in the inbox, unread where they were. */
  function undo(threadIds: string[]): Promise<void> {
    return exclusive(async () => {
      const moves: UndoMove[] = []
      const unread: string[] = []
      const next = new Map(undoable.value)
      for (const threadId of threadIds) {
        const entry = next.get(threadId)
        if (entry !== undefined) {
          moves.push(...entry.moves)
          unread.push(...entry.unread)
          next.delete(threadId)
        }
      }
      undoable.value = next
      await restore({ moves, unread })
    })
  }

  async function restore({ moves, unread }: NotedUndo): Promise<void> {
    let failed = 0
    let reason = ''
    for (const move of moves) {
      try {
        await mail.act(move.messageIds, 'move', { folderId: move.folderId, fromFolderId: move.fromFolderId })
      } catch (error) {
        failed += move.messageIds.length
        reason = errorMessage(error)
      }
    }
    if (unread.length > 0) {
      try {
        await mail.act(unread, 'markUnread')
      } catch (error) {
        failed += unread.length
        reason = errorMessage(error)
      }
    }
    if (failed > 0) {
      compose.notify({ text: `${failed === 1 ? 'One message' : `${failed} messages`} could not be put back: ${reason}`, tone: 'error' })
    }
  }

  /** Moves a conversation that does ask something of the user to To do, and remembers that for its sender. */
  function needsMe(threadId: string): Promise<void> {
    return exclusive(async () => {
      const todo = mail.smartInboxes.find((smartInbox) => smartInbox.builtin === 'todo')
      if (todo === undefined) {
        compose.notify({ text: 'There is no To do smart inbox to move it to.', tone: 'error' })
        return
      }
      try {
        await mail.classifyThread(threadId, todo.id, 'do')
        compose.notify({ text: `Moved to ${todo.name}. Mail like this from the sender goes there too.`, tone: 'info' })
      } catch (error) {
        compose.notify({ text: errorMessage(error), tone: 'error' })
      }
    })
  }

  return { busy, undoable, note, undo, needsMe }
}
