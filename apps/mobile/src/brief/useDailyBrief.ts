import { useCallback, useEffect, useState } from 'react'
import { briefProgress, briefView, groupBrief, threadsToNote, type BriefGroup, type BriefView } from '@tresso/core'
import { noteThreads } from '@/brief/noted'
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

/** "Noted" on the phone (see {@link noteThreads}), one action at a time. */
export function useNoted() {
  const mail = useMail()
  const { notify } = useNotices()
  const { busy, run, exclusive } = useMessageActions()
  const { act, store } = mail

  const note = useCallback(
    (threadIds: string[]) =>
      exclusive(async () => {
        const database = store()
        if (database === null) {
          return
        }
        await noteThreads({ messageIdsOfThreads: (ids) => database.messageIdsOfThreads(ids), folders: () => database.folders(), run, act, notify }, threadIds)
      }),
    [exclusive, store, run, act, notify],
  )

  return { busy, note, noteAll: (view: BriefView) => note(threadsToNote(view.entries)) }
}
