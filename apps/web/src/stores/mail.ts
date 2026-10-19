import { ref, shallowRef, watch } from 'vue'
import { defineStore } from 'pinia'
import {
  RealtimeClient,
  SyncEngine,
  type ChangePage,
  type MessageBody,
  type RealtimeAccess,
  type SendMessageRequest,
  type SyncedMailAccount,
  type SyncedMessage,
  type SyncedOutgoingMessage,
  type SyncedThread,
} from '@tresso/core'
import { deleteMailDatabase, MailDatabase, type Contact, type Conversation, type Draft } from '@/mail/db'
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
  /** Outgoing mail that is scheduled, on its way or failed. */
  const outbox = ref<SyncedOutgoingMessage[]>([])
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
    await refresh()
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

  /** Pulls the delta now (on top of realtime), e.g. when the user asks to refresh. */
  function syncNow(): Promise<void> {
    return pull(() => engine?.sync())
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
    outbox.value = []
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
    const [nextAccounts, nextConversations, nextCounts, nextOutbox] = await Promise.all([
      database.mailAccounts(),
      database.inbox(mailbox.value, listLimit.value + 1),
      database.unreadInboxCounts(),
      database.outbox(),
    ])
    outbox.value = nextOutbox
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

  function send(request: SendMessageRequest): Promise<SyncedOutgoingMessage> {
    return auth.send<SyncedOutgoingMessage>('POST', '/api/v1/outgoing-messages', request)
  }

  function cancelOutgoing(id: string): Promise<SyncedOutgoingMessage> {
    return auth.send<SyncedOutgoingMessage>('POST', `/api/v1/outgoing-messages/${encodeURIComponent(id)}/cancel`, {})
  }

  async function outgoing(id: string): Promise<SyncedOutgoingMessage | null> {
    return (await db.value?.outgoing(id)) ?? null
  }

  async function suggestContacts(query: string): Promise<Contact[]> {
    return (await db.value?.suggestContacts(query, 8)) ?? []
  }

  async function saveDraft(draft: Draft): Promise<void> {
    // Drafts come from reactive state; IndexedDB stores only plain data.
    await db.value?.saveDraft(JSON.parse(JSON.stringify(draft)) as Draft)
  }

  async function drafts(): Promise<Draft[]> {
    return (await db.value?.drafts()) ?? []
  }

  async function draft(id: string): Promise<Draft | null> {
    return (await db.value?.draft(id)) ?? null
  }

  async function deleteDraft(id: string): Promise<void> {
    await db.value?.deleteDraft(id)
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
    outbox,
    mailbox,
    hasMore,
    syncState,
    syncError,
    live,
    ready,
    start,
    stop,
    syncNow,
    forget,
    refresh,
    showMailbox,
    showMore,
    thread,
    body,
    send,
    cancelOutgoing,
    outgoing,
    suggestContacts,
    saveDraft,
    drafts,
    draft,
    deleteDraft,
  }
})
