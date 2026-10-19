import 'fake-indexeddb/auto'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { ChangePage, SyncedSmartInbox } from '@tresso/core'
import { deleteMailDatabase } from '@/mail/db'
import { useMailStore } from '../mail'

const USER = 'classify-user'

function smartInbox(id: string, verdict: SyncedSmartInbox['verdict'], position: number): SyncedSmartInbox {
  return { id, builtin: null, name: id, instructions: '', includeSenders: [], excludeSenders: [], verdict, position }
}

const first: ChangePage = {
  cursor: 1,
  hasMore: false,
  mailAccounts: [{ id: 'work', emailAddress: 'kim@company.example', displayName: 'Work', provider: 'imap', status: 'active', statusDetail: null, lastSyncedAt: null, defaultSignatureId: null }],
  folders: [{ id: 'inbox', mailAccountId: 'work', name: 'INBOX', displayName: 'Inbox', role: 'inbox' }],
  threads: [{ id: 't1', mailAccountId: 'work', subject: 'Budget', messageCount: 1, lastMessageAt: '2026-10-19T08:00:00Z', smartInboxId: 'info', verdict: 'know', classificationReason: 'copied' }],
  messages: [
    {
      id: 'm1',
      mailAccountId: 'work',
      threadId: 't1',
      folderIds: ['inbox'],
      subject: 'Budget',
      from: { address: 'anna@partner.example', name: null },
      recipients: { to: [], cc: [], bcc: [], replyTo: [] },
      snippet: '',
      sentAt: null,
      receivedAt: '2026-10-19T08:00:00Z',
      flags: { seen: false, flagged: false, answered: false, draft: false },
      hasAttachments: false,
      smartInboxId: 'info',
      verdict: 'know',
      summary: null,
    },
  ],
  outgoingMessages: [],
  signatures: [],
  drafts: [],
  smartInboxes: [smartInbox('todo', 'do', 0), smartInbox('info', 'know', 1)],
  deleted: [],
}

describe('classifying a conversation', () => {
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
          return new Response(JSON.stringify(since === 0 ? first : { ...first, cursor: since, mailAccounts: [], folders: [], threads: [], messages: [], smartInboxes: [] }))
        }
        if (!url.pathname.startsWith('/api/v1/threads/')) {
          return new Response('{}', { status: 404 })
        }
        requests.push({ method: init?.method ?? 'GET', path: url.pathname, body: init?.body ? JSON.parse(String(init.body)) : undefined })
        if (refuse) {
          return new Response(JSON.stringify({ detail: 'This smart inbox does not exist.' }), { status: 422 })
        }
        return new Response(JSON.stringify({ threadId: 't1', smartInboxId: 'todo', verdict: 'delegate', reason: 'user', senderRule: null }))
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

  it('moves it at once and tells the server', async () => {
    const mail = useMailStore()
    await mail.showSmartInbox('todo')

    await mail.classifyThread('t1', 'todo', 'delegate')

    expect(requests).toEqual([{ method: 'PUT', path: '/api/v1/threads/t1/classification', body: { smartInboxId: 'todo', verdict: 'delegate' } }])
    expect(mail.conversations.map((c) => [c.threadId, c.verdict, c.classificationReason])).toEqual([['t1', 'delegate', 'user']])
    expect(mail.smartInboxCounts.get('todo')).toBe(1)
  })

  it('leaves the verdict to the smart inbox when none is chosen', async () => {
    await useMailStore().classifyThread('t1', 'todo')

    expect(requests[0]?.body).toEqual({ smartInboxId: 'todo' })
  })

  it('puts it back when the server refuses', async () => {
    const mail = useMailStore()
    await mail.showSmartInbox('info')
    refuse = true

    await expect(mail.classifyThread('t1', 'todo')).rejects.toThrow('This smart inbox does not exist.')

    expect(mail.conversations.map((c) => [c.threadId, c.smartInboxId, c.verdict])).toEqual([['t1', 'info', 'know']])
  })
})
