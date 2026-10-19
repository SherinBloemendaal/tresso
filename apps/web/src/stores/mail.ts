import { ref, shallowRef, watch } from 'vue'
import { defineStore } from 'pinia'
import {
  RealtimeClient,
  SyncEngine,
  type ChangePage,
  type MessageBody,
  type RealtimeAccess,
  type SyncedMailAccount,
  type SyncedMessage,
  type SyncedThread,
} from '@tresso/core'
import { deleteMailDatabase, MailDatabase, type Conversation } from '@/mail/db'
import { useAuthStore } from '@/stores/auth'

const PAGE_SIZE = 500
const LIST_STEP = 100

export type SyncState = 'idle' | 'syncing' | 'error'

/**
 * The signed-in user's mail: a local IndexedDB copy kept current through the delta endpoint, with realtime
 * notifications from the hub triggering pulls. Screens read from the local copy only.
 */
export const useMailStore = defineStore('mail', () => {
  const auth = useAuthStore()

  const accounts = ref<SyncedMailAccount[]>([])
  const conversations = ref<Conversation[]>([])
  const unreadCounts = ref<Map<string, number>>(new Map())
  /** null: the Unified Inbox; otherwise one account's inbox. */
  const mailbox = ref<string | null>(null)
  const listLimit = ref(LIST_STEP)
  const hasMore = ref(false)
  const syncState = ref<SyncState>('idle')
  const syncError = ref<string | null>(null)
  const live = ref(false)
  const ready = ref(false)

  const db = shallowRef<MailDatabase | null>(null)
  let userId: string | null = null
  let engine: SyncEngine | null = null
  let realtime: RealtimeClient | null = null

  async function start(forUserId: string): Promise<void> {
    if (userId === forUserId) {
      return
    }
    await stop()
    userId = forUserId
    db.value = await MailDatabase.open(forUserId)
    await refresh()
    ready.value = true

    const database = db.value
    engine = new SyncEngine({
      store: database,
      pageSize: PAGE_SIZE,
      fetchPage: (since, limit) => auth.api<ChangePage>(`/api/v1/sync/changes?since=${since}&limit=${limit}`),
      onApplied: () => void refresh(),
    })
    realtime = new RealtimeClient({
      fetchAccess: () => auth.api<RealtimeAccess>('/api/v1/realtime/token'),
      onCursor: (cursor) => void pull(() => engine?.notify(cursor)),
      onConnected: () => {
        live.value = true
        void pull(() => engine?.sync())
      },
      onError: () => {
        live.value = false
      },
    })
    await pull(() => engine?.sync())
    realtime.start()
  }

  async function pull(run: () => Promise<void> | undefined): Promise<void> {
    syncState.value = 'syncing'
    try {
      await run()
      syncState.value = 'idle'
      syncError.value = null
    } catch (error) {
      syncState.value = 'error'
      syncError.value = error instanceof Error ? error.message : String(error)
    }
  }

  async function stop(): Promise<void> {
    await realtime?.stop()
    realtime = null
    engine = null
    db.value?.close()
    db.value = null
    userId = null
    live.value = false
    ready.value = false
    accounts.value = []
    conversations.value = []
    unreadCounts.value = new Map()
  }

  /** Stops syncing and removes the local copy, so nothing stays behind on a shared computer. */
  async function forget(): Promise<void> {
    const forUserId = userId
    await stop()
    if (forUserId !== null) {
      await deleteMailDatabase(forUserId)
    }
  }

  async function refresh(): Promise<void> {
    const database = db.value
    if (database === null) {
      return
    }
    const [nextAccounts, nextConversations, nextCounts] = await Promise.all([
      database.mailAccounts(),
      database.inbox(mailbox.value, listLimit.value + 1),
      database.unreadInboxCounts(),
    ])
    accounts.value = nextAccounts
    hasMore.value = nextConversations.length > listLimit.value
    conversations.value = nextConversations.slice(0, listLimit.value)
    unreadCounts.value = nextCounts
  }

  async function showMailbox(mailAccountId: string | null): Promise<void> {
    if (mailbox.value === mailAccountId) {
      return
    }
    mailbox.value = mailAccountId
    listLimit.value = LIST_STEP
    await refresh()
  }

  async function showMore(): Promise<void> {
    listLimit.value += LIST_STEP
    await refresh()
  }

  async function thread(threadId: string): Promise<{ thread: SyncedThread; messages: SyncedMessage[] } | null> {
    return (await db.value?.thread(threadId)) ?? null
  }

  function body(messageId: string, allowRemoteContent: boolean): Promise<MessageBody> {
    const query = allowRemoteContent ? '?remoteContent=allow' : ''

    return auth.api<MessageBody>(`/api/v1/messages/${encodeURIComponent(messageId)}/body${query}`)
  }

  watch(
    () => auth.isAuthenticated,
    (authenticated) => {
      if (!authenticated) {
        void forget()
      }
    },
  )

  return {
    accounts,
    conversations,
    unreadCounts,
    mailbox,
    hasMore,
    syncState,
    syncError,
    live,
    ready,
    start,
    stop,
    forget,
    refresh,
    showMailbox,
    showMore,
    thread,
    body,
  }
})
