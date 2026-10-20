import 'fake-indexeddb/auto'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { ChangePage, SyncedMessage, SyncedThread, SyncedUnsubscription } from '@tresso/core'
import { ApiError } from '@/api/errors'
import { deleteMailDatabase } from '@/mail/db'
import { useMailStore } from '../mail'

const USER = 'batches-user'

function thread(id: string, lastMessageAt: string, extra: Partial<SyncedThread> = {}): SyncedThread {
  return { id, mailAccountId: 'work', subject: id, messageCount: 1, lastMessageAt, smartInboxId: null, verdict: null, classificationReason: null, ...extra }
}

function message(id: string, threadId: string, receivedAt: string, extra: Partial<SyncedMessage> = {}): SyncedMessage {
  return {
    id,
    mailAccountId: 'work',
    threadId,
    folderIds: ['inbox'],
    subject: threadId,
    from: { address: 'News@Shop.example', name: 'Shop' },
    recipients: { to: [], cc: [], bcc: [], replyTo: [] },
    snippet: '',
    sentAt: null,
    receivedAt,
    flags: { seen: false, flagged: false, answered: false, draft: false },
    hasAttachments: false,
    attachmentScan: null,
    smartInboxId: null,
    verdict: null,
    summary: null,
    calendarMethod: null,
    ...extra,
  }
}

const first: ChangePage = {
  cursor: 1,
  hasMore: false,
  mailAccounts: [{ id: 'work', emailAddress: 'kim@company.example', displayName: 'Work', provider: 'imap', status: 'active', statusDetail: null, lastSyncedAt: null, defaultSignatureId: null, calendarStatus: 'unknown', calendarStatusDetail: null }],
  folders: [{ id: 'inbox', mailAccountId: 'work', name: 'INBOX', displayName: 'Inbox', role: 'inbox' }],
  threads: [
    thread('morning', '2026-10-20T07:30:00Z', { batchedAt: '2026-10-20T08:00:00Z' }),
    thread('afternoon', '2026-10-20T15:00:00Z', { batchedAt: '2026-10-20T16:00:00Z' }),
    thread('direct', '2026-10-20T15:30:00Z'),
    thread('held', '2026-10-20T16:30:00Z', { heldUntil: '2099-01-01T08:00:00Z' }),
  ],
  messages: [
    message('m1', 'morning', '2026-10-20T07:30:00Z', { batchedAt: '2026-10-20T08:00:00Z', unsubscribe: 'oneClick' }),
    message('m2', 'afternoon', '2026-10-20T15:00:00Z', { batchedAt: '2026-10-20T16:00:00Z' }),
    message('m3', 'direct', '2026-10-20T15:30:00Z'),
    message('m4', 'held', '2026-10-20T16:30:00Z', { heldUntil: '2099-01-01T08:00:00Z' }),
  ],
  outgoingMessages: [],
  signatures: [],
  drafts: [],
  smartInboxes: [],
  calendars: [],
  calendarEvents: [],
  notifications: [],
  eventNotes: [],
  todos: [],
  mailNotifications: [],
  handOvers: [],
  deleted: [],
}

function unsubscription(status: SyncedUnsubscription['status']): SyncedUnsubscription {
  return { id: 'u1', mailAccountId: 'work', sender: 'news@shop.example', method: 'oneClick', status, error: null, requestedAt: '2026-10-20T16:05:00Z', completedAt: null }
}

describe('the batches and unsubscribing', () => {
  const requests: { method: string; path: string; body: unknown }[] = []
  let refuse: boolean

  beforeEach(async () => {
    sessionStorage.setItem('auth.tokens', JSON.stringify({ accessToken: 'token', refreshToken: null, expiresAt: Date.now() + 3_600_000 }))
    setActivePinia(createPinia())
    requests.length = 0
    refuse = false
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = new URL(String(input))
        if (url.pathname === '/api/v1/sync/changes') {
          const since = Number(url.searchParams.get('since'))
          return new Response(JSON.stringify(since === 0 ? first : { ...first, cursor: since, mailAccounts: [], folders: [], threads: [], messages: [] }))
        }
        if (url.pathname === '/api/v1/unsubscriptions') {
          requests.push({ method: init?.method ?? 'GET', path: url.pathname, body: init?.body ? JSON.parse(String(init.body)) : undefined })
          if (refuse) {
            return new Response(JSON.stringify({ detail: 'This message offers no one-click unsubscribe.' }), { status: 422 })
          }
          return new Response(JSON.stringify(unsubscription('pending')), { status: 202 })
        }
        return new Response('{}', { status: 404 })
      }),
    )
    await useMailStore().start(USER)
  })

  afterEach(async () => {
    await useMailStore().stop()
    await deleteMailDatabase(USER)
    vi.unstubAllGlobals()
    sessionStorage.clear()
  })

  it('lists what the batches delivered, newest batch first, and goes back to an inbox', async () => {
    const mail = useMailStore()
    expect(mail.conversations.map((c) => c.threadId), 'the inbox leaves out held mail').toEqual(['direct', 'afternoon', 'morning'])

    await mail.showBatches()

    expect(mail.batches).toBe(true)
    expect(mail.conversations.map((c) => c.threadId)).toEqual(['afternoon', 'morning'])
    expect(mail.conversations.map((c) => c.batchedAt)).toEqual([Date.parse('2026-10-20T16:00:00Z'), Date.parse('2026-10-20T08:00:00Z')])

    await mail.showMailbox(null)

    expect(mail.batches).toBe(false)
    expect(mail.conversations.map((c) => c.threadId)).toEqual(['direct', 'afternoon', 'morning'])
  })

  it('reads the messages of the conversations it is given', async () => {
    const messages = await useMailStore().threadMessages(['morning', 'gone'])

    expect(messages.get('morning')?.map((m) => m.id)).toEqual(['m1'])
    expect(messages.get('gone')).toEqual([])
  })

  it('asks the server to unsubscribe and keeps its answer at once', async () => {
    const mail = useMailStore()

    const answer = await mail.unsubscribe('m1')

    expect(requests).toEqual([{ method: 'POST', path: '/api/v1/unsubscriptions', body: { messageId: 'm1' } }])
    expect(answer.status).toBe('pending')
    expect(mail.unsubscriptions).toEqual([unsubscription('pending')])
  })

  it('says why the server refused and stores nothing', async () => {
    const mail = useMailStore()
    refuse = true

    const failure = await mail.unsubscribe('m1').catch((error: unknown) => error)

    expect(failure).toBeInstanceOf(ApiError)
    expect((failure as ApiError).message).toBe('This message offers no one-click unsubscribe.')
    expect(mail.unsubscriptions).toEqual([])
  })
})
