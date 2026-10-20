import { useCallback, useEffect, useState } from 'react'
import { briefProgress, briefView, groupBrief, threadsToNote, undoPlan, type BriefGroup, type BriefView, type UndoMove } from '@tresso/core'
import { useNotices } from '@/components/Notices'
import { useMail } from '@/mail/MailProvider'
import { useMessageActions } from '@/mail/useMessageActions'

/** The newest daily brief from the copy on the phone, kept current with it, and how many of it are left to note. */
export function useLatestBrief(): { view: BriefView | null; groups: BriefGroup[]; left: number; loaded: boolean } {
  const { store, version } = useMail()
  const [state, setState] = useState<{ view: BriefView | null; groups: BriefGroup[]; loaded: boolean }>({ view: null, groups: [], loaded: false })

  useEffect(() => {
    let current = true
    const database = store()
    if (database === null) {
      return
    }
    void (async () => {
      const [briefs, smartInboxes] = await Promise.all([database.dailyBriefs(), database.smartInboxes()])
      const newest = briefs[0]
      const view = newest === undefined ? null : briefView(newest, await database.conversations(newest.threadIds))
      if (current) {
        setState({ view, groups: view === null ? [] : groupBrief(view.entries, 'smartInbox', smartInboxes), loaded: true })
      }
    })()
    return () => {
      current = false
    }
  }, [store, version])

  const progress = briefProgress(state.view?.entries ?? [])
  return { ...state, left: progress.total - progress.noted }
}

/**
 * "Noted" on the phone, the same as on the web: the conversations are marked read and archived through the message
 * actions, and the notice offers an undo that puts back where they were and what was unread.
 */
export function useNoted() {
  const mail = useMail()
  const { notify } = useNotices()
  const { busy, run, exclusive } = useMessageActions()
  const { act } = mail

  const restore = useCallback(
    async (moves: UndoMove[], unread: string[]) => {
      let failed = 0
      let reason = ''
      for (const move of moves) {
        try {
          await act(move.messageIds, 'move', { folderId: move.folderId, fromFolderId: move.fromFolderId })
        } catch (error) {
          failed += move.messageIds.length
          reason = error instanceof Error ? error.message : String(error)
        }
      }
      if (unread.length > 0) {
        try {
          await act(unread, 'markUnread')
        } catch (error) {
          failed += unread.length
          reason = error instanceof Error ? error.message : String(error)
        }
      }
      if (failed > 0) {
        notify({ text: `${failed === 1 ? 'One message' : `${failed} messages`} could not be put back: ${reason}`, tone: 'error' })
      }
    },
    [act, notify],
  )

  const note = useCallback(
    (threadIds: string[]) =>
      exclusive(async () => {
        const database = mail.store()
        if (database === null || threadIds.length === 0) {
          return
        }
        const messageIds = await database.messageIdsOfThreads(threadIds)
        const read = await run(messageIds, 'markRead')
        if (read === null) {
          return
        }
        const unread = read.before.filter((message) => !message.flags.seen).map((message) => message.id)
        const archived = await run(messageIds, 'archive')
        if (archived === null) {
          await restore([], unread)
          return
        }
        const plan = undoPlan(archived, 'archive', await database.folders())
        notify({
          text: threadIds.length === 1 ? 'Noted: read and archived.' : `${threadIds.length} conversations noted: read and archived.`,
          tone: 'info',
          action: plan.moves.length > 0 ? { label: 'Undo', accessibilityLabel: 'Undo noted', run: () => void restore(plan.moves, unread) } : undefined,
        })
      }),
    [exclusive, mail, run, restore, notify],
  )

  return { busy, note, noteAll: (view: BriefView) => note(threadsToNote(view.entries)) }
}
