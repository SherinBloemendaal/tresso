import { ref, shallowRef, watch } from 'vue'
import { defineStore } from 'pinia'
import {
  RealtimeClient,
  SyncEngine,
  MAX_MESSAGES_PER_ACTION,
  appliedFor,
  CopyClosingError,
  DraftSync,
  PartialActionError,
  type AppliedAction,
  type ChangePage,
  type MessageAction,
  type MessageActionRequest,
  type MessageActionResult,
  type MessageBody,
  type RealtimeAccess,
  type MailStore,
  type SendMessageRequest,
  type SyncedDraft,
  type SyncedFolder,
  type SyncedMailAccount,
  type SyncedMessage,
  type SyncedOutgoingMessage,
  type SyncedSignature,
  type SyncedThread,
} from '@tresso/core'
import type { Contact, Conversation, Draft } from '@/mail/db'
import { deleteMailStore, openMailStore } from '@/mail/store'
import { freshArrivals, mailNotifications } from '@/mail/mailNotifications'
import { notifier } from '@/platform/notifier'
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
  /** Every folder of every account, kept with the rest, so screens know folder roles without waiting. */
  const allFolders = ref<SyncedFolder[]>([])
  const signatures = ref<SyncedSignature[]>([])
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

  const db = shallowRef<MailStore | null>(null)
  let userId: string | null = null
  let engine: SyncEngine | null = null
  let realtime: RealtimeClient | null = null
  /**
   * When notifications were switched on: after the first complete pull, so the mail that was already there (or that
   * arrived while the app was closed) does not pop up all at once. null until then.
   */
  let notifyingSince: number | null = null
  /**
   * Raised by every start and stop. A start overtaken while it waited (by a stop, or by another start) gives up, so it
   * never overwrites a newer session's state; the copy it opened is closed again.
   */
  let generation = 0
  /** Opens of a copy under way, with the close of one that was overtaken; removing a copy waits for them. */
  const openings = new Set<Promise<void>>()

  async function start(forUserId: string): Promise<void> {
    if (userId === forUserId) {
      return
    }
    // Claimed before anything is awaited, so a sign-out (or another start) while the old copy closes overtakes it.
    const mine = ++generation
    await teardown()
    if (mine !== generation) {
      return
    }
    userId = forUserId
    let opened!: () => void
    const opening = new Promise<void>((resolve) => {
      opened = resolve
    })
    openings.add(opening)
    let database: MailStore
    try {
      database = await openMailStore(forUserId)
      if (mine !== generation) {
        await database.close()
        return
      }
      db.value = database
    } catch (error) {
      if (mine !== generation) {
        // Overtaken anyway (a sign-out, another user): its failure is no longer anyone's concern.
        return
      }
      throw error
    } finally {
      openings.delete(opening)
      opened()
    }
    await refresh()
    if (mine !== generation) {
      return
    }
    ready.value = true

    engine = new SyncEngine({
      store: database,
      pageSize: PAGE_SIZE,
      fetchPage: (since, limit) => auth.api<ChangePage>(`/api/v1/sync/changes?since=${since}&limit=${limit}`),
      onApplied: () => {
        void refresh()
        void notifyArrivals()
      },
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
    if (mine !== generation) {
      return
    }
    await refresh()
    void uploadPendingDrafts()
    realtime.start()
  }

  async function pull(run: () => Promise<void> | undefined): Promise<void> {
    syncState.value = 'syncing'
    try {
      await run()
      syncState.value = 'idle'
      syncError.value = null
      if (notifyingSince === null) {
        db.value?.takeArrivals()
        notifyingSince = Date.now()
      }
    } catch (error) {
      if (error instanceof CopyClosingError) {
        // A page that arrived while the copy closed (a sign-out or another user): nothing is wrong.
        syncState.value = 'idle'
        return
      }
      syncState.value = 'error'
      syncError.value = error instanceof Error ? error.message : String(error)
    }
  }

  /** Pulls changes now, e.g. right after a write or when the user asks, instead of waiting for realtime. */
  async function syncNow(): Promise<void> {
    await pull(() => engine?.sync())
    await refresh()
  }

  /** Tells the user about new mail that needs them, unless they are looking at Tresso already. */
  async function notifyArrivals(): Promise<void> {
    const database = db.value
    if (database === null || notifyingSince === null) {
      return
    }
    const arrivals = freshArrivals(database.takeArrivals(), notifyingSince)
    if (arrivals.length === 0 || notifier === null || document.hasFocus()) {
      return
    }
    try {
      await notifier.show(mailNotifications(arrivals))
    } catch (error) {
      console.error('Showing a notification failed', error)
    }
  }

  /** Stops syncing and closes the copy; a start still under way gives up. */
  async function stop(): Promise<void> {
    generation += 1
    await teardown()
  }

  /** Everything is cleared before the first wait, so a start that runs meanwhile begins from a clean state. */
  async function teardown(): Promise<void> {
    const stopping = realtime
    const database = db.value
    realtime = null
    engine = null
    notifyingSince = null
    db.value = null
    userId = null
    live.value = false
    ready.value = false
    accounts.value = []
    allFolders.value = []
    signatures.value = []
    conversations.value = []
    unreadCounts.value = new Map()
    outbox.value = []
    await stopping?.stop()
    await database?.close()
  }

  /** Stops syncing and removes the local copy, so nothing stays behind on a shared computer. */
  async function forget(): Promise<void> {
    const forUserId = userId
    await stop()
    // A copy still opening (at launch, say) is closed by its start once it sees it was overtaken; only then can it go.
    await Promise.allSettled(openings)
    if (forUserId !== null) {
      await deleteMailStore(forUserId)
    }
  }

  async function refresh(): Promise<void> {
    const database = db.value
    if (database === null) {
      return
    }
    const [nextAccounts, nextFolders, nextSignatures, nextConversations, nextCounts, nextOutbox] = await Promise.all([
      database.mailAccounts(),
      database.folders(),
      database.signatures(),
      database.inbox(mailbox.value, listLimit.value + 1),
      database.unreadInboxCounts(),
      database.outbox(),
    ])
    outbox.value = nextOutbox
    accounts.value = nextAccounts
    allFolders.value = nextFolders
    signatures.value = nextSignatures
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

  /** Threads in the mailbox on screen from any of these senders (lower-case addresses), loaded in the list or not. */
  async function threadsFrom(addresses: Set<string>): Promise<string[]> {
    return (await db.value?.inboxThreadIds(mailbox.value, (c) => c.senders.some((sender) => addresses.has(sender.address.toLowerCase())))) ?? []
  }

  /** Threads in the mailbox on screen whose newest message is older than this moment (epoch ms). */
  async function threadsBefore(moment: number): Promise<string[]> {
    return (await db.value?.inboxThreadIds(mailbox.value, (c) => c.lastMessageAt < moment)) ?? []
  }

  /** Which selected threads are still in the mailbox on screen, and their accounts and senders. */
  async function describeThreads(threadIds: string[]): Promise<{ present: string[]; accounts: string[]; senders: string[] }> {
    return (await db.value?.describeThreads(mailbox.value, threadIds)) ?? { present: [], accounts: [], senders: [] }
  }

  async function messageIdsOf(threadIds: string[]): Promise<string[]> {
    return (await db.value?.messageIdsOfThreads(threadIds)) ?? []
  }

  /** The messages of each thread, to tell which threads an action took completely. */
  async function messagesByThread(threadIds: string[]): Promise<Map<string, string[]>> {
    return (await db.value?.messagesByThread(threadIds)) ?? new Map()
  }

  async function folders(): Promise<SyncedFolder[]> {
    return (await db.value?.folders()) ?? []
  }

  function body(messageId: string, allowRemoteContent: boolean): Promise<MessageBody> {
    const query = allowRemoteContent ? '?remoteContent=allow' : ''

    return auth.api<MessageBody>(`/api/v1/messages/${encodeURIComponent(messageId)}/body${query}`)
  }

  /**
   * Reads, flags, archives, trashes or moves messages: the change shows at once and is undone here for the messages
   * the server did not take. Large selections go in batches of {@link MAX_MESSAGES_PER_ACTION}. Returns the messages
   * before and after, to build an undo from.
   */
  async function act(messageIds: string[], action: MessageAction, target: { folderId?: string; fromFolderId?: string } = {}): Promise<AppliedAction> {
    const database = db.value
    if (database === null || messageIds.length === 0) {
      return { before: [], after: [] }
    }
    const applied = await database.applyAction(messageIds, action, target.folderId, target.fromFolderId)
    await refresh()
    let accepted = 0
    try {
      for (let start = 0; start < messageIds.length; start += MAX_MESSAGES_PER_ACTION) {
        const request: MessageActionRequest = { messageIds: messageIds.slice(start, start + MAX_MESSAGES_PER_ACTION), action }
        if (target.folderId !== undefined) {
          request.folderId = target.folderId
        }
        if (target.fromFolderId !== undefined) {
          request.fromFolderId = target.fromFolderId
        }
        await auth.send<MessageActionResult>('POST', '/api/v1/message-actions', request)
        accepted = start + request.messageIds.length
      }
    } catch (error) {
      // Earlier batches went through; only the rest goes back.
      await database.revertAction(appliedFor(applied, new Set(messageIds.slice(accepted))))
      await refresh()
      void syncNow()
      if (accepted > 0) {
        throw new PartialActionError(error, appliedFor(applied, new Set(messageIds.slice(0, accepted))), messageIds.length - accepted)
      }
      throw error
    }
    void syncNow()
    return applied
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

  /** Saves on this device; the upload follows (see uploadDraft). */
  async function saveDraft(draft: Draft): Promise<void> {
    // Drafts come from reactive state; IndexedDB stores only plain data.
    await db.value?.saveDraft(JSON.parse(JSON.stringify(draft)) as Draft)
  }

  const draftSync = new DraftSync(
    () => db.value,
    {
      create: (id, request) => auth.send<SyncedDraft>('POST', '/api/v1/drafts', { ...request, draftId: id }),
      update: (id, request, expectedVersion) => auth.send<SyncedDraft>('PUT', `/api/v1/drafts/${encodeURIComponent(id)}`, { ...request, expectedVersion }),
      get: (id) => auth.api<SyncedDraft>(`/api/v1/drafts/${encodeURIComponent(id)}`),
      remove: (id) => auth.send<void>('DELETE', `/api/v1/drafts/${encodeURIComponent(id)}`),
    },
    () => void refresh(),
  )

  /** Brings the draft's unsaved edits to the server (see DraftSync.upload). */
  function uploadDraft(id: string): Promise<void> {
    return draftSync.upload(id)
  }

  /** Uploads every draft with edits that did not reach the server yet, e.g. after starting offline. */
  function uploadPendingDrafts(): Promise<void> {
    return draftSync.uploadPending()
  }

  /** Settles a conflict: keep the edits made here (they overwrite the other version) or take the other version. */
  function resolveDraftConflict(id: string, keep: 'mine' | 'theirs'): Promise<Draft | null> {
    return draftSync.resolveConflict(id, keep)
  }

  /** Removes the draft here and on the server, and with it its copy in the Drafts folder. */
  function deleteDraftEverywhere(id: string): Promise<void> {
    return draftSync.deleteEverywhere(id)
  }

  /** Saves and uploads the draft before sending; true when the server has it. */
  function uploadDraftForSending(draft: Draft): Promise<boolean> {
    return draftSync.uploadForSending(JSON.parse(JSON.stringify(draft)) as Draft)
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
    allFolders,
    signatures,
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
    act,
    forget,
    refresh,
    showMailbox,
    showMore,
    thread,
    threadsFrom,
    threadsBefore,
    describeThreads,
    messageIdsOf,
    messagesByThread,
    folders,
    body,
    send,
    cancelOutgoing,
    outgoing,
    suggestContacts,
    saveDraft,
    drafts,
    draft,
    deleteDraft,
    uploadDraft,
    uploadDraftForSending,
    uploadPendingDrafts,
    resolveDraftConflict,
    deleteDraftEverywhere,
  }
})
