import { useCallback, useRef, useState } from 'react'
import { PartialActionError, undoPlan, type AppliedAction, type MessageAction, type SyncedFolder, type UndoMove } from '@tresso/core'
import { useNotices } from '@/components/Notices'
import { useMail } from '@/mail/MailProvider'

function errorText(error: unknown): string {
  return error instanceof Error ? error.message : String(error)
}

/**
 * Message actions as screens use them: errors become notices, one action runs at a time, and archiving, trashing or
 * moving offers an undo that puts back exactly what can be (the same plan as the web client's).
 */
export function useMessageActions() {
  const mail = useMail()
  const { notify } = useNotices()
  const [busy, setBusy] = useState(false)
  const running = useRef(false)
  const before = useRef<Promise<void>>(Promise.resolve())
  const { act } = mail

  /** Lets actions taken from now on wait for this first (reading a conversation on open). */
  const after = useCallback((task: Promise<unknown>) => {
    before.current = task.then(
      () => undefined,
      () => undefined,
    )
  }, [])

  const run = useCallback(
    async (messageIds: string[], action: MessageAction, folderId?: string): Promise<AppliedAction | null> => {
      try {
        return await act(messageIds, action, { folderId })
      } catch (error) {
        if (error instanceof PartialActionError) {
          notify({ text: `${error.rejected === 1 ? 'One message was' : `${error.rejected} messages were`} not changed: ${errorText(error.reason)}`, tone: 'error' })
          return error.accepted
        }
        notify({ text: errorText(error), tone: 'error' })
        return null
      }
    },
    [act, notify],
  )

  /** One action at a time: a second tap during an action is ignored rather than acting on its result. */
  const exclusive = useCallback(async (task: () => Promise<void>) => {
    if (running.current) {
      return
    }
    running.current = true
    setBusy(true)
    try {
      await before.current
      await task()
    } finally {
      running.current = false
      setBusy(false)
    }
  }, [])

  const undo = useCallback(
    async (moves: UndoMove[]) => {
      let failed = 0
      let reason = ''
      for (const move of moves) {
        try {
          await act(move.messageIds, 'move', { folderId: move.folderId, fromFolderId: move.fromFolderId })
        } catch (error) {
          failed += move.messageIds.length
          reason = errorText(error)
        }
      }
      if (failed > 0) {
        notify({ text: `${failed === 1 ? 'One message' : `${failed} messages`} could not be put back: ${reason}`, tone: 'error' })
      }
    },
    [act, notify],
  )

  /** Archives, trashes or moves the messages and says so, with an undo for what can be put back exactly. */
  const relocate = useCallback(
    async (messageIds: string[], action: 'archive' | 'trash' | 'move', folders: SyncedFolder[], folderId?: string): Promise<AppliedAction | null> => {
      const applied = await run(messageIds, action, folderId)
      if (applied === null) {
        return null
      }
      const label = action === 'archive' ? 'Archived' : action === 'trash' ? 'Moved to Trash' : `Moved to ${folders.find((folder) => folder.id === folderId)?.displayName ?? 'the folder'}`
      const plan = undoPlan(applied, action, folders)
      const left = plan.unrestorable
      const caveat = left === 0 || plan.moves.length === 0 ? '' : ` Undo cannot put ${left === 1 ? 'one message' : `${left} messages`} back.`
      const what = action === 'archive' ? 'archive' : action === 'trash' ? 'move to Trash' : 'move'
      notify({ text: `${label}.${caveat}`, tone: 'info', action: plan.moves.length > 0 ? { label: 'Undo', accessibilityLabel: `Undo ${what}`, run: () => void undo(plan.moves) } : undefined })
      return applied
    },
    [run, undo, notify],
  )

  return { busy, after, run, exclusive, relocate }
}
