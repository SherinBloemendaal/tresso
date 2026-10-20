import { useEffect, useReducer, useRef, useState } from 'react'
import { currentTriage, triageProgress, type TriageHooks } from '@tresso/core'
import { useHandOvers } from '@/handOver/HandOverProvider'
import { useNotices } from '@/components/Notices'
import { useMail } from '@/mail/MailProvider'
import { useMessageActions } from '@/mail/useMessageActions'
import { copyChanged, createTriageDriver } from '@/mail/triageSession'

/**
 * Triage on the phone: the same round as the web client (`TriageSession` in `@tresso/core`), with the same hooks. It
 * starts once the copy has pulled what the server has and the smart inboxes are there, and follows every change of
 * the copy after that.
 *
 * @param hooks where other features plug in; by default do makes the conversation's todo and hand over opens the
 *   hand-over screen
 */
export function useTriage(hooks: Partial<TriageHooks> = {}) {
  const mail = useMail()
  const handOvers = useHandOvers()
  const { notify } = useNotices()
  const { run, undo: undoMoves } = useMessageActions()
  const [, rerender] = useReducer((count: number) => count + 1, 0)
  const [smartInboxCount, setSmartInboxCount] = useState(0)
  // The round lives as long as the screen and reads the latest of these whenever it calls them.
  const [driver] = useState(() => createTriageDriver(rerender))
  const { session } = driver
  useEffect(() => {
    driver.setHost({ mail, handOvers, notify, run, undoMoves, hooks })
  })

  const { ready, synced, version, smartInboxes } = mail
  useEffect(() => {
    let current = true
    if (ready) {
      void smartInboxes().then((list) => current && setSmartInboxCount(list.length))
    }
    return () => {
      current = false
    }
  }, [ready, version, smartInboxes])

  // Starts once the copy holds what the server has and the smart inboxes are there (so Spam is known); follows every
  // change of the copy after that.
  const started = useRef(false)
  useEffect(() => {
    started.current = copyChanged(session, started.current, { synced, smartInboxes: smartInboxCount })
  }, [session, synced, smartInboxCount, version])

  const current = session.state === null ? null : currentTriage(session.state)
  return {
    state: session.state,
    current,
    conversation: session.conversation?.thread.id === current ? session.conversation : null,
    progress: session.state === null ? null : triageProgress(session.state),
    canUndo: session.canUndo,
    saving: session.pending > 0,
    more: session.more,
    decide: session.decide.bind(session),
    skip: session.skip.bind(session),
    undo: () => session.undo(),
    nextRound: () => void session.begin(),
  }
}
