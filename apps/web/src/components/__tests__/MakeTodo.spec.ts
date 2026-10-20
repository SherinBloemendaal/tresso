import 'fake-indexeddb/auto'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import type { ChangePage, SyncedMessage, Todo } from '@tresso/core'
import { deleteMailDatabase } from '@/mail/db'
import { useMailStore } from '@/stores/mail'
import ReadingPane from '../ReadingPane.vue'

const account = { id: 'work', emailAddress: 'kim@company.example', displayName: 'Work', provider: 'imap', status: 'active' as const, statusDetail: null, lastSyncedAt: null, defaultSignatureId: null, calendarStatus: 'unknown' as const, calendarStatusDetail: null }

function message(id: string, from: string, receivedAt: string): SyncedMessage {
  return {
    id,
    mailAccountId: 'work',
    threadId: 't1',
    folderIds: ['inbox'],
    subject: 'Offsite plan',
    from: { address: from, name: null },
    recipients: { to: [], cc: [], bcc: [], replyTo: [] },
    snippet: '',
    sentAt: null,
    receivedAt,
    flags: { seen: true, flagged: false, answered: false, draft: false },
    hasAttachments: false,
    smartInboxId: null,
    verdict: null,
    summary: null,
    calendarMethod: null,
    attachmentScan: null,
  }
}

function page(cursor: number, extra: Partial<ChangePage> = {}): ChangePage {
  return { cursor, hasMore: false, mailAccounts: [], folders: [], threads: [], messages: [], outgoingMessages: [], signatures: [], drafts: [], smartInboxes: [], calendars: [], calendarEvents: [], notifications: [], eventNotes: [], todos: [], mailNotifications: [], deleted: [], ...extra }
}

const made: Todo = {
  id: 'todo-1',
  title: 'Offsite plan',
  notes: '',
  dueDate: null,
  dueTime: null,
  durationMinutes: null,
  completedAt: null,
  position: 0,
  source: 'manual',
  messageId: 'm2',
  threadId: 't1',
  createdAt: '2026-10-19T10:00:00Z',
  updatedAt: '2026-10-19T10:00:00Z',
}

describe('Make todo in the reading pane', () => {
  const requests: { method: string; path: string }[] = []
  let router: Router
  let todoMade: boolean
  /** Todos the conversation has before the pane opens. */
  let existing: Todo[]

  beforeEach(async () => {
    sessionStorage.setItem('auth.tokens', JSON.stringify({ accessToken: 'token', refreshToken: null, expiresAt: Date.now() + 3_600_000 }))
    setActivePinia(createPinia())
    requests.length = 0
    todoMade = false
    existing = []
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = new URL(String(input))
        const method = init?.method ?? 'GET'
        requests.push({ method, path: url.pathname })
        if (url.pathname === '/api/v1/sync/changes') {
          const since = Number(url.searchParams.get('since'))
          if (since === 0) {
            return new Response(
              JSON.stringify(
                page(1, {
                  mailAccounts: [account],
                  folders: [{ id: 'inbox', mailAccountId: 'work', name: 'INBOX', displayName: 'Inbox', role: 'inbox' }],
                  threads: [{ id: 't1', mailAccountId: 'work', subject: 'Offsite plan', messageCount: 3, lastMessageAt: '2026-10-19T10:00:00Z', smartInboxId: null, verdict: null, classificationReason: null }],
                  messages: [
                    message('m1', 'anna@partner.example', '2026-10-19T08:00:00Z'),
                    message('m2', 'lisa@partner.example', '2026-10-19T09:00:00Z'),
                    message('m3', 'Kim@Company.example', '2026-10-19T10:00:00Z'),
                  ],
                }),
              ),
            )
          }
          return new Response(JSON.stringify(since === 1 && (todoMade || existing.length > 0) ? page(2, { todos: todoMade ? [made] : existing }) : page(since)))
        }
        if (url.pathname === '/api/v1/messages/m2/todo' && method === 'POST') {
          todoMade = true
          return new Response(JSON.stringify(made))
        }
        return new Response(JSON.stringify({ detail: 'unavailable' }), { status: 503 })
      }),
    )
    await useMailStore().start('make-todo-user')
    router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', name: 'unified', component: { template: '<div />' } },
        { path: '/inbox/:threadId', name: 'unified-thread', component: { template: '<div />' } },
        { path: '/todos/:todoId', name: 'todo', component: { template: '<div />' } },
      ],
    })
    await router.push('/inbox/t1')
  })

  afterEach(async () => {
    await useMailStore().stop()
    await deleteMailDatabase('make-todo-user')
    vi.unstubAllGlobals()
    sessionStorage.clear()
  })

  function mountPane() {
    return mount(ReadingPane, { props: { threadId: 't1', backTo: '/' }, global: { plugins: [router] }, attachTo: document.body })
  }

  it('makes a todo from the newest email someone else sent and shows it instead of the button', async () => {
    const wrapper = mountPane()
    await vi.waitFor(() => expect(wrapper.find('button[aria-keyshortcuts="T"]').exists()).toBe(true))

    await wrapper.get('button[aria-keyshortcuts="T"]').trigger('click')
    await flushPromises()

    await vi.waitFor(() => expect(wrapper.find('ul[aria-label="Todos from this conversation"]').exists()).toBe(true))
    expect(requests.filter((request) => request.method === 'POST')).toEqual([{ method: 'POST', path: '/api/v1/messages/m2/todo' }])
    expect(wrapper.get('ul[aria-label="Todos from this conversation"] a').attributes('href')).toBe('/todos/todo-1')
    expect(wrapper.get('ul[aria-label="Todos from this conversation"]').text()).toContain('Offsite plan')
    expect(wrapper.find('button[aria-keyshortcuts="T"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('makes a todo with the T key', async () => {
    const wrapper = mountPane()
    await vi.waitFor(() => expect(wrapper.find('button[aria-keyshortcuts="T"]').exists()).toBe(true))

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 't' }))

    await vi.waitFor(() => expect(requests.some((request) => request.path === '/api/v1/messages/m2/todo')).toBe(true))
    wrapper.unmount()
  })

  it('leaves T alone once the conversation has a todo, like the button', async () => {
    existing = [made]
    await useMailStore().syncNow()
    const wrapper = mountPane()
    await vi.waitFor(() => expect(wrapper.find('ul[aria-label="Todos from this conversation"]').exists()).toBe(true))

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 't' }))
    await flushPromises()

    expect(wrapper.find('button[aria-keyshortcuts="T"]').exists()).toBe(false)
    expect(requests.filter((request) => request.method === 'POST')).toEqual([])
    wrapper.unmount()
  })
})
