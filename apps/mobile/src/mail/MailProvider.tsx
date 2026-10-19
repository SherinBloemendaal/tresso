import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { AppState } from 'react-native'
import { RealtimeClient, SyncEngine, type ChangePage, type ConversationSummary, type MessageBody, type SyncedMailAccount, type RealtimeAccess, type SyncedMessage, type SyncedThread } from '@tresso/core'
import { useAuth } from '@/auth/AuthProvider'
import { MailDatabase } from '@/mail/mailDatabase'
import { deleteSql, openSql } from '@/mail/sql'

const PAGE_SIZE = 500
const LIST_LIMIT = 200

export type SyncState = 'idle' | 'syncing' | 'error'

interface Mail {
  ready: boolean
  /** Goes up whenever the local copy changed, so screens read it again. */
  version: number
  syncState: SyncState
  syncError: string | null
  /** The realtime connection is up, so changes arrive as they happen. */
  live: boolean
  mailAccounts(): Promise<SyncedMailAccount[]>
  inbox(mailAccountId: string | null): Promise<ConversationSummary[]>
  thread(threadId: string): Promise<{ thread: SyncedThread; messages: SyncedMessage[] } | null>
  body(messageId: string, allowRemoteContent: boolean): Promise<MessageBody>
  syncNow(): Promise<void>
}

const MailContext = createContext<Mail | null>(null)

function databaseName(userId: string): string {
  return `mail-${userId}.db`
}

/**
 * The signed-in user's mail on the device: a SQLite copy kept current through the delta endpoint, with realtime
 * notifications from the hub triggering pulls while the app is in the foreground. Screens read from the copy only.
 * Signing out stops syncing and removes the copy.
 */
export function MailProvider({ children }: { children: ReactNode }) {
  const auth = useAuth()
  const [signedInId, setSignedInId] = useState<string | null>(null)
  // Whose copy is open: nobody's while signed out, also before the next sign-in has said who it is.
  const userId = auth.isAuthenticated ? signedInId : null
  const [ready, setReady] = useState(false)
  const [version, setVersion] = useState(0)
  const [syncState, setSyncState] = useState<SyncState>('idle')
  const [syncError, setSyncError] = useState<string | null>(null)
  const [live, setLive] = useState(false)
  const database = useRef<MailDatabase | null>(null)
  const engine = useRef<SyncEngine | null>(null)
  const realtime = useRef<RealtimeClient | null>(null)
  const previousUser = useRef<string | null>(null)

  // Who is signed in decides whose copy is open.
  useEffect(() => {
    if (!auth.isAuthenticated) {
      return
    }
    let current = true
    auth.session
      .me()
      .then((me) => current && setSignedInId(me.id))
      .catch((error: unknown) => current && setSyncError(error instanceof Error ? error.message : String(error)))
    return () => {
      current = false
      // Signing out forgets who it was, so the next sign-in starts from nobody.
      setSignedInId(null)
    }
  }, [auth.isAuthenticated, auth.session])

  const pull = useCallback(async (run: () => Promise<void> | undefined) => {
    setSyncState('syncing')
    try {
      await run()
      setSyncState('idle')
      setSyncError(null)
    } catch (error) {
      setSyncState('error')
      setSyncError(error instanceof Error ? error.message : String(error))
    }
  }, [])

  useEffect(() => {
    if (userId === null) {
      // Signed out: the copy of whoever was signed in goes, so nothing stays behind on the device.
      const leaving = previousUser.current
      previousUser.current = null
      if (leaving !== null) {
        void deleteSql(databaseName(leaving)).catch(() => {})
      }
      return
    }
    previousUser.current = userId
    let stopped = false
    let close: (() => Promise<void>) | null = null

    void (async () => {
      const sql = await openSql(databaseName(userId))
      close = sql.close
      if (stopped) {
        await sql.close()
        return
      }
      const db = await MailDatabase.open(sql)
      database.current = db
      setReady(true)
      setVersion((v) => v + 1)
      const sync = new SyncEngine({
        store: db,
        pageSize: PAGE_SIZE,
        fetchPage: (since, limit) => auth.session.api<ChangePage>(`/api/v1/sync/changes?since=${since}&limit=${limit}`),
        onApplied: () => setVersion((v) => v + 1),
      })
      engine.current = sync
      realtime.current = new RealtimeClient({
        fetchAccess: () => auth.session.api<RealtimeAccess>('/api/v1/realtime/token'),
        onCursor: (cursor) => void pull(() => sync.notify(cursor)),
        onConnected: () => {
          setLive(true)
          void pull(() => sync.sync())
        },
        onError: () => setLive(false),
      })
      await pull(() => sync.sync())
      if (!stopped && AppState.currentState === 'active') {
        realtime.current.start()
      }
    })()

    // In the background the connection rests; back in front, the copy catches up and listens again.
    const subscription = AppState.addEventListener('change', (state) => {
      const client = realtime.current
      if (client === null) {
        return
      }
      if (state === 'active') {
        client.start()
        void pull(() => engine.current?.sync())
      } else {
        setLive(false)
        void client.stop()
      }
    })

    return () => {
      stopped = true
      subscription.remove()
      setReady(false)
      setLive(false)
      const client = realtime.current
      realtime.current = null
      engine.current = null
      database.current = null
      void (async () => {
        await client?.stop()
        await close?.()
      })()
    }
  }, [userId, auth.session, pull])

  const mailAccounts = useCallback(async () => (await database.current?.mailAccounts()) ?? [], [])
  const inbox = useCallback(async (mailAccountId: string | null) => (await database.current?.inbox(mailAccountId, LIST_LIMIT)) ?? [], [])
  const thread = useCallback(async (threadId: string) => (await database.current?.thread(threadId)) ?? null, [])
  const body = useCallback(
    (messageId: string, allowRemoteContent: boolean) =>
      auth.session.api<MessageBody>(`/api/v1/messages/${encodeURIComponent(messageId)}/body${allowRemoteContent ? '?remoteContent=allow' : ''}`),
    [auth.session],
  )
  const syncNow = useCallback(() => pull(() => engine.current?.sync()), [pull])
  const mail = useMemo<Mail>(
    () => ({ ready, version, syncState, syncError, live, mailAccounts, inbox, thread, body, syncNow }),
    [ready, version, syncState, syncError, live, mailAccounts, inbox, thread, body, syncNow],
  )

  return <MailContext.Provider value={mail}>{children}</MailContext.Provider>
}

export function useMail(): Mail {
  const mail = useContext(MailContext)
  if (mail === null) {
    throw new Error('useMail() is used outside <MailProvider>.')
  }
  return mail
}
