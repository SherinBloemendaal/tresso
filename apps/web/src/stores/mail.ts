import { ref, shallowRef, watch } from 'vue'
import { defineStore } from 'pinia'
import {
  RealtimeClient,
  SyncEngine,
  type ChangePage,
  type MessageBody,
  type RealtimeAccess,
  type DraftRequest,
  type SendMessageRequest,
  type SyncedDraft,
  type SyncedFolder,
  type SyncedMailAccount,
  type SyncedMessage,
  type SyncedOutgoingMessage,
  type SyncedSignature,
  type SyncedThread,
} from '@tresso/core'
import { deleteMailDatabase, MailDatabase, type Contact, type Conversation, type Draft } from '@/mail/db'
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

  const db = shallowRef<MailDatabase | null>(null)
  let userId: string | null = null
  let engine: SyncEngine | null = null
  let realtime: RealtimeClient | null = null
  /**
   * When notifications were switched on: after the first complete pull, so the mail that was already there (or that
   * arrived while the app was closed) does not pop up all at once. null until then.
   */
  let notifyingSince: number | null = null

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

  async function stop(): Promise<void> {
    await realtime?.stop()
    realtime = null
    engine = null
    notifyingSince = null
    db.value?.close()
    db.value = null
    userId = null
    live.value = false
    ready.value = false
    accounts.value = []
    signatures.value = []
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
    const [nextAccounts, nextSignatures, nextConversations, nextCounts, nextOutbox] = await Promise.all([
      database.mailAccounts(),
      database.signatures(),
      database.inbox(mailbox.value, listLimit.value + 1),
      database.unreadInboxCounts(),
      database.outbox(),
    ])
    outbox.value = nextOutbox
    accounts.value = nextAccounts
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

  async function folders(): Promise<SyncedFolder[]> {
    return (await db.value?.folders()) ?? []
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
    forget,
    refresh,
    showMailbox,
    showMore,
    thread,
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
