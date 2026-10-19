import { ref, shallowRef, watch } from 'vue'
import { defineStore } from 'pinia'
import {
  RealtimeClient,
  SyncEngine,
  MAX_MESSAGES_PER_ACTION,
  appliedFor,
  CopyClosingError,
  PartialActionError,
  type AppliedAction,
  type ChangePage,
  type MessageAction,
  type MessageActionRequest,
  type MessageActionResult,
  type MessageBody,
  type RealtimeAccess,
  type DraftRequest,
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
import { ApiError } from '@/api/errors'
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

  async function start(forUserId: string): Promise<void> {
    if (userId === forUserId) {
      return
    }
    await stop()
    const mine = ++generation
    userId = forUserId
    const database = await openMailStore(forUserId)
    if (mine !== generation) {
      await database.close()
      return
    }
    db.value = database
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

  /** Everything is cleared before the first wait, so a start that runs meanwhile begins from a clean state. */
  async function stop(): Promise<void> {
    generation += 1
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

  const uploads = new Map<string, Promise<void>>()
  const uploadAgain = new Set<string>()

  /**
   * Brings the draft's unsaved edits to the server. Uploads of one draft never overlap: an upload asked for during
   * one runs right after it, with whatever was saved by then. Another device's newer version is never overwritten;
   * it is noted on the draft for the user to settle (see resolveDraftConflict).
   */
  function uploadDraft(id: string): Promise<void> {
    uploadAgain.add(id)
    const running = uploads.get(id)
    if (running) return running
    const run = (async () => {
      try {
        while (uploadAgain.delete(id)) {
          await uploadOnce(id)
        }
      } finally {
        uploads.delete(id)
      }
    })()
    uploads.set(id, run)
    return run
  }

  async function uploadOnce(id: string): Promise<void> {
    const database = db.value
    const local = await database?.draft(id)
    if (!database || !local || !local.pendingSave || local.remoteVersion !== null || local.outgoingId !== null) return

    const request: DraftRequest = {
      mailAccountId: local.mailAccountId,
      kind: local.kind,
      to: local.to,
      cc: local.cc,
      bcc: local.bcc,
      subject: local.subject,
      html: local.html,
      signatureId: local.signatureId,
      quoteHtml: local.quoteHtml,
      inReplyToMessageId: local.inReplyToMessageId,
    }
    let saved: SyncedDraft
    try {
      saved =
        local.version === 0
          ? await auth.send<SyncedDraft>('POST', '/api/v1/drafts', { ...request, draftId: id })
          : await auth.send<SyncedDraft>('PUT', `/api/v1/drafts/${encodeURIComponent(id)}`, { ...request, expectedVersion: local.version })
    } catch (e) {
      if (e instanceof ApiError && e.status === 409 && local.version > 0) {
        const remote = await auth.api<SyncedDraft>(`/api/v1/drafts/${encodeURIComponent(id)}`)
        await database.saveDraft({ ...local, remoteVersion: remote.version })
        await refresh()
        return
      }
      if (e instanceof ApiError && e.status === 404 && local.version > 0) {
        // Deleted on another device while it was being written here: it becomes a new draft again.
        await database.saveDraft({ ...local, version: 0 })
        uploadAgain.add(id)
        return
      }
      throw e
    }
    // Edits made while the upload was under way stay pending and go up in the next round.
    const current = (await database.draft(id)) ?? local
    const unchanged = current.updatedAt === local.updatedAt
    await database.saveDraft({ ...current, version: saved.version, pendingSave: !unchanged })
    if (!unchanged) uploadAgain.add(id)
  }

  /** Uploads every draft with edits that did not reach the server yet, e.g. after starting offline. */
  async function uploadPendingDrafts(): Promise<void> {
    for (const draft of (await db.value?.drafts()) ?? []) {
      if (draft.pendingSave && draft.remoteVersion === null && draft.outgoingId === null) {
        await uploadDraft(draft.id).catch(() => undefined)
      }
    }
  }

  /** Settles a conflict: keep the edits made here (they overwrite the other version) or take the other version. */
  async function resolveDraftConflict(id: string, keep: 'mine' | 'theirs'): Promise<Draft | null> {
    const database = db.value
    const local = await database?.draft(id)
    if (!database || !local || local.remoteVersion === null) return local ?? null
    const remote = await auth.api<SyncedDraft>(`/api/v1/drafts/${encodeURIComponent(id)}`)
    const next: Draft =
      keep === 'mine'
        ? { ...local, version: remote.version, remoteVersion: null, pendingSave: true }
        : { ...remote, updatedAt: Date.parse(remote.updatedAt), pendingSave: false, remoteVersion: null, outgoingId: null, noticedStatus: null }
    await database.saveDraft(next)
    if (keep === 'mine') await uploadDraft(id)
    return (await database.draft(id)) ?? next
  }

  /** Removes the draft here and on the server, and with it its copy in the Drafts folder. */
  async function deleteDraftEverywhere(id: string): Promise<void> {
    const local = await db.value?.draft(id)
    if (local && local.version > 0) {
      try {
        await auth.send<void>('DELETE', `/api/v1/drafts/${encodeURIComponent(id)}`)
      } catch (e) {
        if (!(e instanceof ApiError && e.status === 404)) throw e
      }
    }
    await db.value?.deleteDraft(id)
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
    uploadPendingDrafts,
    resolveDraftConflict,
    deleteDraftEverywhere,
  }
})
