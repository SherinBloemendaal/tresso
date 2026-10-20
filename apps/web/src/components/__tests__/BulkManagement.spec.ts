import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { BulkActionState, CleanupSuggestion, SmartInboxSenders } from '@tresso/core'
import { useMailStore } from '@/stores/mail'
import CleanupPanel from '../CleanupPanel.vue'
import SmartInboxManage from '../SmartInboxManage.vue'

const senders: SmartInboxSenders = {
  id: 'news',
  conversations: 7,
  messages: 9,
  unread: 6,
  senders: [
    { address: 'news@shop.example', name: 'Shop', conversations: 5, messages: 6, unread: 6, lastReceivedAt: '2026-10-19T08:00:00+00:00' },
    { address: 'digest@dev.example', name: null, conversations: 2, messages: 3, unread: 0, lastReceivedAt: '2026-10-18T08:00:00+00:00' },
  ],
}

const oldNews: CleanupSuggestion = {
  id: 'oldInSmartInbox:news',
  kind: 'oldInSmartInbox',
  conversations: 40,
  unread: null,
  bytes: null,
  sender: null,
  senderName: null,
  smartInboxId: 'news',
  windowDays: null,
  windowReceived: null,
  olderThanDays: 30,
  largerThan: null,
  action: 'archive',
  scope: { in: 'smartInbox', smartInboxId: 'news', sender: null, olderThanDays: 30, largerThan: null },
}

const done: BulkActionState = {
  id: 'b1',
  action: 'archive',
  folderId: null,
  scope: null,
  status: 'done',
  conversations: 5,
  changed: 6,
  unrestorable: 0,
  restored: 0,
  canUndo: true,
  error: null,
  createdAt: '2026-10-20T10:00:00+00:00',
  finishedAt: '2026-10-20T10:00:01+00:00',
}

function last<T>(items: T[]): T | undefined {
  return items[items.length - 1]
}

async function settle(): Promise<void> {
  for (let i = 0; i < 4; i++) {
    await flushPromises()
  }
}

describe('bulk management', () => {
  const requests: { method: string; path: string; body: unknown }[] = []
  let suggestions: CleanupSuggestion[]

  beforeEach(() => {
    sessionStorage.setItem('auth.tokens', JSON.stringify({ accessToken: 'token', refreshToken: null, expiresAt: Date.now() + 3_600_000 }))
    setActivePinia(createPinia())
    requests.length = 0
    suggestions = [oldNews]
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = new URL(String(input))
        const method = init?.method ?? 'GET'
        requests.push({ method, path: `${url.pathname}${url.search}`, body: init?.body ? JSON.parse(String(init.body)) : undefined })
        switch (url.pathname) {
          case '/api/v1/smart-inboxes/news/senders':
            return new Response(JSON.stringify(senders))
          case '/api/v1/cleanup-suggestions':
            return new Response(JSON.stringify({ suggestions }))
          case '/api/v1/bulk-selection':
            return new Response(JSON.stringify({ conversations: url.searchParams.has('olderThanDays') ? 3 : 7, messages: 9, mailAccountIds: ['work'] }))
          default:
            return new Response(JSON.stringify(done), { status: 202 })
        }
      }),
    )
    const mail = useMailStore()
    vi.spyOn(mail, 'syncNow').mockResolvedValue()
    mail.smartInboxes = [{ id: 'news', name: 'Newsletters', instructions: '', includeSenders: [], excludeSenders: [], verdict: 'know', builtin: 'advertise', autoCreateTodo: false, position: 0 }]
    mail.accounts = [{ id: 'work', emailAddress: 'kim@company.example', displayName: 'Work', provider: 'imap', status: 'active', statusDetail: null, lastSyncedAt: null, defaultSignatureId: null, calendarStatus: 'unknown', calendarStatusDetail: null }]
    mail.allFolders = [{ id: 'projects', mailAccountId: 'work', name: 'Projects', displayName: 'Projects', role: 'custom' }]
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    sessionStorage.clear()
  })

  function router() {
    return createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }, { path: '/smart/:smartInboxId', name: 'smart-inbox', component: { template: '<div />' } }, { path: '/smart/:smartInboxId/manage', name: 'smart-inbox-manage', component: { template: '<div />' } }] })
  }

  it('lists the senders of a smart inbox with how much of their mail is read', async () => {
    const wrapper = mount(SmartInboxManage, { props: { smartInboxId: 'news' }, global: { plugins: [router()] } })
    await settle()

    expect(wrapper.find('.subtitle').text()).toBe('7 conversations from 2 senders, 6 unread')
    const rows = wrapper.findAll('[role="row"]').slice(1)
    expect(rows.map((row) => row.find('.sender-address').text())).toEqual(['news@shop.example', 'digest@dev.example'])
    expect(rows[0]!.find('.rate .mono').text()).toBe('0%')
    expect(rows[1]!.find('.rate .mono').text()).toBe('100%')
    expect(rows[1]!.text()).not.toContain('Mark read')
    expect(wrapper.find('.suggested').text()).toContain('40 conversations in Newsletters had no new mail for over a month.')
  })

  it('asks with the count before acting on everything older than an age', async () => {
    const wrapper = mount(SmartInboxManage, { props: { smartInboxId: 'news' }, global: { plugins: [router()] } })
    await settle()

    await wrapper.findAll('.age').find((button) => button.text() === 'Older than a month')!.trigger('click')
    await wrapper.findAll('.toolbar .tool').find((button) => button.text() === 'Archive')!.trigger('click')
    await settle()

    expect(last(requests)?.path).toBe('/api/v1/bulk-selection?in=smartInbox&smartInboxId=news&olderThanDays=30')
    expect(wrapper.find('[role="alertdialog"]').text()).toContain('Archive 3 conversations in Newsletters with no new mail for over a month?')
    expect(requests.some((request) => request.method === 'POST')).toBe(false)

    await wrapper.find('[role="alertdialog"] .button-primary').trigger('click')
    await settle()

    expect(requests.find((request) => request.method === 'POST')).toEqual({ method: 'POST', path: '/api/v1/bulk-actions', body: { in: 'smartInbox', smartInboxId: 'news', olderThanDays: 30, action: 'archive' } })
    expect(wrapper.find('[role="alertdialog"]').exists()).toBe(false)
  })

  it('moves within the folder\'s account', async () => {
    const wrapper = mount(SmartInboxManage, { props: { smartInboxId: 'news' }, global: { plugins: [router()] } })
    await settle()

    await wrapper.find('[aria-haspopup="menu"]').trigger('click')
    await wrapper.find('[role="menuitem"]').trigger('click')
    await settle()
    await wrapper.find('[role="alertdialog"] .button-primary').trigger('click')
    await settle()

    expect(requests.find((request) => request.method === 'POST')?.body).toEqual({ in: 'smartInbox', smartInboxId: 'news', olderThanDays: null, action: 'move', folderId: 'projects', mailAccountId: 'work' })
  })

  it('acts on one sender at once', async () => {
    const wrapper = mount(SmartInboxManage, { props: { smartInboxId: 'news' }, global: { plugins: [router()] } })
    await settle()

    await wrapper.find('[aria-label="Archive mail from news@shop.example"]').trigger('click')
    await settle()

    expect(requests.find((request) => request.method === 'POST')?.body).toEqual({ in: 'smartInbox', smartInboxId: 'news', sender: 'news@shop.example', olderThanDays: null, action: 'archive' })
  })

  it('cleans up a suggestion in one click and looks again', async () => {
    const wrapper = mount(CleanupPanel, { global: { plugins: [router()] } })
    await settle()

    expect(wrapper.find('h2').text()).toBe('Newsletters older than a month')
    suggestions = []
    await wrapper.find('.card button').trigger('click')
    await settle()

    expect(requests.find((request) => request.method === 'POST')?.body).toEqual({ ...oldNews.scope, action: 'archive' })
    expect(wrapper.find('.empty').text()).toBe('Nothing to clean up right now.')
  })
})
