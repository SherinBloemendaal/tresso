import 'fake-indexeddb/auto'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import type { ChangePage, DailyBriefSettings, SyncedMessage, SyncedSmartInbox } from '@tresso/core'
import { deleteMailDatabase } from '@/mail/db'
import { useMailStore } from '@/stores/mail'
import DailyBriefView from '../DailyBriefView.vue'

const account = { id: 'work', emailAddress: 'kim@company.example', displayName: 'Work', provider: 'imap', status: 'active' as const, statusDetail: null, lastSyncedAt: null, defaultSignatureId: null, calendarStatus: 'unknown' as const, calendarStatusDetail: null }

function smartInbox(id: string, builtin: SyncedSmartInbox['builtin'], name: string, position: number): SyncedSmartInbox {
  return { id, builtin, name, instructions: '', includeSenders: [], excludeSenders: [], verdict: builtin === 'todo' ? 'do' : 'know', position, autoCreateTodo: false }
}

function message(id: string, threadId: string, from: string, smartInboxId: string): SyncedMessage {
  return {
    id,
    mailAccountId: 'work',
    threadId,
    folderIds: ['inbox'],
    subject: `Subject ${threadId}`,
    from: { address: from, name: null },
    recipients: { to: [], cc: [], bcc: [], replyTo: [] },
    snippet: `Snippet ${threadId}`,
    sentAt: null,
    receivedAt: '2026-10-20T07:00:00Z',
    flags: { seen: false, flagged: false, answered: false, draft: false },
    hasAttachments: false,
    smartInboxId,
    verdict: 'know',
    summary: null,
    calendarMethod: null,
    attachmentScan: null,
  }
}

const settings: DailyBriefSettings = {
  enabled: true,
  deliverAt: '08:00',
  timeZone: 'UTC',
  channels: [],
  emailAddress: 'kim@example.test',
  nextBriefAt: '2026-10-21T08:00:00+00:00',
}

/** A storage that behaves like the browser's, for the remembered brief and grouping. */
function memoryStorage(): Storage {
  const items = new Map<string, string>()
  return {
    get length() {
      return items.size
    },
    clear: () => items.clear(),
    getItem: (key) => items.get(key) ?? null,
    key: (index) => [...items.keys()][index] ?? null,
    removeItem: (key) => void items.delete(key),
    setItem: (key, value) => void items.set(key, String(value)),
  }
}

/** Requests run asynchronously; let them and the pulls they start finish. */
async function settle(): Promise<void> {
  for (let i = 0; i < 8; i++) {
    await flushPromises()
    await new Promise((resolve) => setTimeout(resolve, 0))
  }
}

describe('DailyBriefView', () => {
  const requests: { method: string; path: string; body: unknown }[] = []
  let router: Router
  let wrapper: VueWrapper | null = null

  beforeEach(async () => {
    sessionStorage.setItem('auth.tokens', JSON.stringify({ accessToken: 'token', refreshToken: null, expiresAt: Date.now() + 3_600_000 }))
    vi.stubGlobal('localStorage', memoryStorage())
    setActivePinia(createPinia())
    requests.length = 0
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = new URL(String(input))
        const method = init?.method ?? 'GET'
        requests.push({ method, path: url.pathname, body: init?.body ? JSON.parse(String(init.body)) : undefined })
        if (url.pathname === '/api/v1/sync/changes') {
          const since = Number(url.searchParams.get('since'))
          const first: ChangePage = {
            cursor: 1,
            hasMore: false,
            mailAccounts: [account],
            folders: [
              { id: 'inbox', mailAccountId: 'work', name: 'INBOX', displayName: 'Inbox', role: 'inbox' },
              { id: 'archive', mailAccountId: 'work', name: 'Archive', displayName: 'Archive', role: 'archive' },
            ],
            threads: ['t1', 't2', 't3'].map((id, i) => ({ id, mailAccountId: 'work', subject: `Subject ${id}`, messageCount: 1, lastMessageAt: '2026-10-20T07:00:00Z', smartInboxId: i === 2 ? 'notifications' : 'info', verdict: 'know', classificationReason: 'mailing_list' })),
            messages: [
              message('m1', 't1', 'news@lists.example', 'info'),
              message('m2', 't2', 'news@lists.example', 'info'),
              message('m3', 't3', 'ci@forge.example', 'notifications'),
            ],
            outgoingMessages: [],
            signatures: [],
            drafts: [],
            smartInboxes: [smartInbox('todo', 'todo', 'To do', 0), smartInbox('info', 'informational', 'Informational', 1), smartInbox('notifications', 'notification', 'Notifications', 2)],
            calendars: [],
            calendarEvents: [],
            notifications: [],
            eventNotes: [],
            todos: [],
            mailNotifications: [],
            handOvers: [],
            dailyBriefs: [
              { id: 'b1', coversFrom: '2026-10-19T08:00:00+00:00', coversUntil: '2026-10-20T07:59:59+00:00', threadIds: ['t1', 't2', 't3'], createdAt: '2026-10-20T08:00:00+00:00' },
              { id: 'b0', coversFrom: '2026-10-18T08:00:00+00:00', coversUntil: '2026-10-19T07:59:59+00:00', threadIds: ['t1'], createdAt: '2026-10-19T08:00:00+00:00' },
            ],
            deleted: [],
          }
          return new Response(JSON.stringify(since === 0 ? first : { ...first, cursor: since, mailAccounts: [], folders: [], threads: [], messages: [], smartInboxes: [], dailyBriefs: [] }))
        }
        if (url.pathname === '/api/v1/daily-brief-settings') {
          return new Response(JSON.stringify(settings))
        }
        if (url.pathname === '/api/v1/message-actions') {
          return new Response(JSON.stringify({ changed: 1 }))
        }
        if (url.pathname.endsWith('/classification')) {
          return new Response(JSON.stringify({ threadId: 't3', smartInboxId: 'todo', verdict: 'do', reason: 'user', senderRule: null }))
        }
        return new Response('{}', { status: 404 })
      }),
    )
    await useMailStore().start('brief-user')
    router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', name: 'unified', component: { template: '<p>inbox</p>' } },
        { path: '/inbox/:threadId', name: 'unified-thread', component: { template: '<p>thread</p>' } },
        { path: '/brief', name: 'brief', component: DailyBriefView },
        { path: '/brief/:briefId', name: 'brief-edition', component: DailyBriefView },
        { path: '/todos', name: 'todos', component: { template: '<p>todos</p>' } },
        { path: '/batches', name: 'batches', component: { template: '<p>batches</p>' } },
        { path: '/cleanup', name: 'cleanup', component: { template: '<p>cleanup</p>' } },
        { path: '/waiting', name: 'waiting', component: { template: '<p>waiting</p>' } },
        { path: '/drafts', name: 'drafts', component: { template: '<p>drafts</p>' } },
        { path: '/outbox', name: 'outbox', component: { template: '<p>outbox</p>' } },
        { path: '/smart/:smartInboxId', name: 'smart-inbox', component: { template: '<p>smart</p>' } },
        { path: '/accounts/:accountId', name: 'account', component: { template: '<p>account</p>' } },
        { path: '/settings', name: 'settings-accounts', component: { template: '<p>settings</p>' } },
        { path: '/settings/brief', name: 'settings-brief', component: { template: '<p>brief settings</p>' } },
        { path: '/settings/accounts/new', name: 'settings-accounts-new', component: { template: '<p>new</p>' } },
        { path: '/settings/smart-inboxes/:smartInboxId', name: 'settings-smart-inbox', component: { template: '<p>smart inbox</p>' } },
      ],
    })
    await router.push('/brief')
    await router.isReady()
    wrapper = mount(DailyBriefView, { global: { plugins: [router] }, attachTo: document.body })
    await settle()
  })

  afterEach(async () => {
    wrapper?.unmount()
    await settle()
    await useMailStore().stop()
    await deleteMailDatabase('brief-user')
    vi.unstubAllGlobals()
    sessionStorage.clear()
  })

  function rows() {
    return wrapper!.findAll('.item').filter((row) => row.find('.subject').exists())
  }

  it('shows the newest brief per smart inbox with subject, preview and progress', () => {
    const groups = wrapper!.findAll('section.group')
    expect(groups.map((group) => group.get('.group-title').text())).toEqual(['Informational 2', 'Notifications 1'])
    expect(rows().map((row) => row.get('.subject').text())).toEqual(['Subject t1', 'Subject t2', 'Subject t3'])
    expect(rows()[0]!.text()).toContain('Snippet t1')
    expect(wrapper!.get('.progress').text()).toBe('0 of 3 noted')
    expect(wrapper!.text()).toContain('The next brief arrives')
  })

  it('groups per sender on request', async () => {
    await wrapper!.findAll('[role="radio"]')[1]!.trigger('click')

    expect(wrapper!.findAll('section.group').map((group) => group.get('.group-title').text())).toEqual(['news@lists.example 2', 'ci@forge.example 1'])
  })

  function actions(): unknown[] {
    return requests.filter((request) => request.path === '/api/v1/message-actions').map((request) => request.body)
  }

  it('notes a conversation by reading and archiving it, and can put it back', async () => {
    const noted = rows()[0]!.findAll('button').find((button) => button.text() === 'Noted')!
    await noted.trigger('click')

    await vi.waitFor(() => expect(wrapper!.get('.progress').text()).toBe('1 of 3 noted'))
    expect(actions()).toEqual([
      { messageIds: ['m1'], action: 'markRead' },
      { messageIds: ['m1'], action: 'archive' },
    ])
    await vi.waitFor(() => expect(rows()[0]!.findAll('button').some((button) => button.text() === 'Undo')).toBe(true))
    expect(rows()[0]!.find('.noted-chip').exists()).toBe(true)

    requests.length = 0
    await rows()[0]!.findAll('button').find((button) => button.text() === 'Undo')!.trigger('click')

    await vi.waitFor(() => expect(wrapper!.get('.progress').text()).toBe('0 of 3 noted'))
    await vi.waitFor(() => expect(actions()).toHaveLength(2))
    expect(actions()).toEqual([
      { messageIds: ['m1'], action: 'move', folderId: 'inbox', fromFolderId: 'archive' },
      { messageIds: ['m1'], action: 'markUnread' },
    ])
  })

  it('marks the whole brief noted with Shift K', async () => {
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'K', shiftKey: true }))

    await vi.waitFor(() => expect(wrapper!.get('.progress').text()).toBe('All 3 noted'))
    expect((actions() as { action: string; messageIds: string[] }[]).map((action) => [action.action, [...action.messageIds].sort()])).toEqual([
      ['markRead', ['m1', 'm2', 'm3']],
      ['archive', ['m1', 'm2', 'm3']],
    ])
  })

  it('moves a conversation that does need the user to To do', async () => {
    await rows()[2]!.findAll('button').find((button) => button.text() === 'Needs me')!.trigger('click')

    await vi.waitFor(() =>
      expect(requests.find((request) => request.path.endsWith('/classification'))).toEqual({ method: 'PUT', path: '/api/v1/threads/t3/classification', body: { smartInboxId: 'todo', verdict: 'do' } }),
    )
  })

  it('remembers the brief as seen', () => {
    expect(localStorage.getItem('tresso.brief.seen')).toBe('b1')
  })

  it('never archives a conversation moved to To do when the whole brief is noted', async () => {
    await rows()[2]!.findAll('button').find((button) => button.text() === 'Needs me')!.trigger('click')
    const t3 = () => rows().find((row) => row.get('.subject').text() === 'Subject t3')!
    await vi.waitFor(() => expect(t3().find('.moved-chip').exists()).toBe(true))
    expect(t3().get('.moved-chip').text()).toBe('Moved to To do')
    expect(t3().findAll('button').some((button) => button.text().includes('Noted'))).toBe(false)
    expect(wrapper!.get('.progress').text()).toBe('0 of 2 noted')

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'K', shiftKey: true }))

    await vi.waitFor(() => expect(wrapper!.get('.progress').text()).toBe('All 2 noted'))
    expect((actions() as { action: string; messageIds: string[] }[]).map((action) => [action.action, [...action.messageIds].sort()])).toEqual([
      ['markRead', ['m1', 'm2']],
      ['archive', ['m1', 'm2']],
    ])
  })

  it('leaves the newest brief new when an older one is opened', async () => {
    localStorage.clear()
    await router.push('/brief/b0')
    await settle()

    expect(rows().map((row) => row.get('.subject').text())).toEqual(['Subject t1'])
    expect(localStorage.getItem('tresso.brief.seen')).toBeNull()
  })
})
