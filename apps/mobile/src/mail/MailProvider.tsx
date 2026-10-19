import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { AppState } from 'react-native'
import { fetch as streamingFetch } from 'expo/fetch'
import * as SecureStore from 'expo-secure-store'
import {
  type AppliedAction,
  type ChangePage,
  type ConversationSummary,
  type MessageAction,
  type MessageBody,
  type RealtimeAccess,
  type SyncedFolder,
  type SyncedMailAccount,
  type SyncedMessage,
  type SyncedThread,
} from '@tresso/core'
import { useAuth } from '@/auth/AuthProvider'
import { LAST_USER } from '@/auth/secureTokenStore'
import { MailSession, type MailState } from '@/mail/mailSession'
import { deleteSql, openSql } from '@/mail/sql'

const LIST_LIMIT = 200
/** Wait before asking again who is signed in, after that failed (offline at start, server down). */
const ME_RETRY_MS = 10_000

interface Mail extends Omit<MailState, 'userId'> {
  mailAccounts(): Promise<SyncedMailAccount[]>
  folders(): Promise<SyncedFolder[]>
  /**
   * Reads, flags, archives, trashes or moves messages: the change shows at once and is undone for the messages the
   * server did not take. Large selections go in batches. Returns the messages before and after, for an undo.
   */
  act(messageIds: string[], action: MessageAction, target?: { folderId?: string; fromFolderId?: string }): Promise<AppliedAction>
  inbox(mailAccountId: string | null): Promise<ConversationSummary[]>
  thread(threadId: string): Promise<{ thread: SyncedThread; messages: SyncedMessage[] } | null>
  body(messageId: string, allowRemoteContent: boolean): Promise<MessageBody>
  syncNow(): Promise<void>
}

const MailContext = createContext<Mail | null>(null)

/**
 * The signed-in user's mail on the device (see {@link MailSession}): screens read from the copy only. Signing out
 * closes the copy and removes it.
 */
export function MailProvider({ children }: { children: ReactNode }) {
  const auth = useAuth()
  const [state, setState] = useState<MailState>({ userId: null, ready: false, version: 0, syncState: 'idle', syncError: null, live: false })
  const [lookupError, setLookupError] = useState<string | null>(null)
  const session = useMemo(
    () =>
      new MailSession({
        openSql,
        deleteSql,
        fetchPage: (since, limit) => auth.session.api<ChangePage>(`/api/v1/sync/changes?since=${since}&limit=${limit}`),
        fetchAccess: () => auth.session.api<RealtimeAccess>('/api/v1/realtime/token'),
        postAction: async (request) => {
          await auth.session.api('/api/v1/message-actions', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(request) })
        },
        // expo/fetch streams response bodies, which the realtime connection reads.
        fetch: streamingFetch as unknown as typeof fetch,
        onState: setState,
        foreground: AppState.currentState === 'active',
      }),
    [auth.session],
  )

  // Who is signed in decides whose copy is open. The last known user's copy opens at once, so mail on the device
  // shows offline too; the server then confirms who it is (asked again until it answers), and a different answer
  // switches to that user's copy, which removes the other.
  useEffect(() => {
    if (auth.restoring) {
      return
    }
    if (!auth.isAuthenticated) {
      // The token store forgets who was signed in together with the tokens.
      void session.switchTo(null)
      return
    }
    let current = true
    let answered = false
    let retry: ReturnType<typeof setTimeout> | undefined
    void SecureStore.getItemAsync(LAST_USER)
      .then((last) => {
        // Only until the server said who it is: its answer always wins.
        if (current && !answered && last !== null) {
          void session.switchTo(last)
        }
      })
      .catch(() => {})
    const lookUp = () => {
      auth.session
        .me()
        .then((me) => {
          if (current) {
            answered = true
            setLookupError(null)
            void session.switchTo(me.id)
            void SecureStore.setItemAsync(LAST_USER, me.id).catch(() => {})
          }
        })
        .catch((error: unknown) => {
          if (current) {
            setLookupError(error instanceof Error ? error.message : String(error))
            retry = setTimeout(lookUp, ME_RETRY_MS)
          }
        })
    }
    lookUp()
    return () => {
      current = false
      clearTimeout(retry)
    }
  }, [auth.restoring, auth.isAuthenticated, auth.session, session])

  // In the background the connection rests; back in front, the copy catches up and listens again.
  useEffect(() => {
    const subscription = AppState.addEventListener('change', (next) => session.setForeground(next === 'active'))
    return () => subscription.remove()
  }, [session])

  const mailAccounts = useCallback(async () => (await session.database?.mailAccounts()) ?? [], [session])
  const folders = useCallback(async () => (await session.database?.folders()) ?? [], [session])
  const act = useCallback(
    (messageIds: string[], action: MessageAction, target: { folderId?: string; fromFolderId?: string } = {}) => session.act(messageIds, action, target),
    [session],
  )
  const inbox = useCallback(async (mailAccountId: string | null) => (await session.database?.inbox(mailAccountId, LIST_LIMIT)) ?? [], [session])
  const thread = useCallback(async (threadId: string) => (await session.database?.thread(threadId)) ?? null, [session])
  const body = useCallback(
    (messageId: string, allowRemoteContent: boolean) =>
      auth.session.api<MessageBody>(`/api/v1/messages/${encodeURIComponent(messageId)}/body${allowRemoteContent ? '?remoteContent=allow' : ''}`),
    [auth.session],
  )
  const syncNow = useCallback(() => session.syncNow(), [session])
  const { ready, version, syncState, syncError, live } = state
  const mail = useMemo<Mail>(
    () => ({ ready, version, syncState, syncError: syncError ?? lookupError, live, mailAccounts, folders, act, inbox, thread, body, syncNow }),
    [ready, version, syncState, syncError, lookupError, live, mailAccounts, folders, act, inbox, thread, body, syncNow],
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
