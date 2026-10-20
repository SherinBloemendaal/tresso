import 'fake-indexeddb/auto'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import type { ChangePage, SyncedSmartInbox } from '@tresso/core'
import { deleteMailDatabase } from '@/mail/db'
import { useMailStore } from '@/stores/mail'
import ConversationList from '../ConversationList.vue'
import NoticeStack from '../NoticeStack.vue'

const USER = 'list-classify-user'

function smartInbox(id: string, builtin: SyncedSmartInbox['builtin'], name: string, verdict: SyncedSmartInbox['verdict'], position: number): SyncedSmartInbox {
  return { id, builtin, name, instructions: '', includeSenders: [], excludeSenders: [], verdict, autoCreateTodo: false, position }
}

const first: ChangePage = {
  cursor: 1,
  hasMore: false,
  mailAccounts: [{ id: 'work', emailAddress: 'kim@company.example', displayName: 'Work', provider: 'imap', status: 'active', statusDetail: null, lastSyncedAt: null, defaultSignatureId: null, calendarStatus: 'unknown', calendarStatusDetail: null }],
  folders: [{ id: 'junk', mailAccountId: 'work', name: 'Junk', displayName: 'Junk', role: 'spam' }],
  threads: [{ id: 't1', mailAccountId: 'work', subject: 'Statement', messageCount: 1, lastMessageAt: '2026-10-19T08:00:00Z', smartInboxId: 'spam', verdict: 'none', classificationReason: 'server_spam' }],
  messages: [
    {
      id: 'm1',
      mailAccountId: 'work',
      threadId: 't1',
      folderIds: ['junk'],
      subject: 'Statement',
      from: { address: 'news@bank.example', name: null },
      recipients: { to: [], cc: [], bcc: [], replyTo: [] },
      snippet: '',
      sentAt: null,
      receivedAt: '2026-10-19T08:00:00Z',
      flags: { seen: false, flagged: false, answered: false, draft: false },
      hasAttachments: false,
      attachmentScan: null,
      smartInboxId: 'spam',
      verdict: 'none',
      summary: null,
      calendarMethod: null,
    },
  ],
  outgoingMessages: [],
  signatures: [],
  drafts: [],
  smartInboxes: [smartInbox('info', 'informational', 'Informational', 'know', 1), smartInbox('spam', 'spam', 'Spam', 'none', 4)],
  calendars: [],
  calendarEvents: [],
  notifications: [],
  eventNotes: [],
  todos: [],
  deleted: [],
}

describe('classifying from the conversation list', () => {
  let router: Router

  beforeEach(async () => {
    sessionStorage.setItem('auth.tokens', JSON.stringify({ accessToken: 'token', refreshToken: null, expiresAt: Date.now() + 3_600_000 }))
    setActivePinia(createPinia())
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => {
        const url = new URL(String(input))
        if (url.pathname === '/api/v1/sync/changes') {
          const since = Number(url.searchParams.get('since'))
          return new Response(JSON.stringify(since === 0 ? first : { ...first, cursor: since, mailAccounts: [], folders: [], threads: [], messages: [], smartInboxes: [] }))
        }
        if (url.pathname === '/api/v1/threads/t1/classification') {
          return new Response(JSON.stringify({ threadId: 't1', smartInboxId: 'info', verdict: 'know', reason: 'user', senderRule: { id: 'r1', sender: 'news@bank.example', verdict: null, spamOverrules: 'server_spam' } }))
        }
        return new Response('{}', { status: 404 })
      }),
    )
    await useMailStore().start(USER)
    router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }, { path: '/smart/:id/:threadId', name: 'smart-inbox-thread', component: { template: '<div />' } }] })
    await router.push('/')
  })

  afterEach(async () => {
    await useMailStore().stop()
    await deleteMailDatabase(USER)
    vi.unstubAllGlobals()
    sessionStorage.clear()
  })

  it('still says the sender keeps going to Spam after the row left the smart inbox', async () => {
    const mail = useMailStore()
    await mail.showSmartInbox('spam')
    const Screen = defineComponent(() => () => [
      h(ConversationList, {
        title: 'Spam',
        conversations: mail.conversations,
        selectedThreadId: null,
        hasMore: false,
        loading: false,
        hasAccounts: true,
        linkTo: (id: string) => `/smart/spam/${id}`,
      }),
      h(NoticeStack),
    ])
    const wrapper = mount(Screen, { global: { plugins: [router] }, attachTo: document.body })

    await wrapper.get('[aria-label^="No verdict"], [aria-label^="None, in Spam"]').trigger('click')
    await wrapper.findAll('[role="menuitemradio"]').find((item) => item.text().startsWith('Informational'))!.trigger('click')
    await flushPromises()
    await vi.waitFor(() => expect(mail.conversations).toEqual([]))

    expect(wrapper.text()).toContain('Moved. Your mail server marks mail from news@bank.example as spam, so new mail from them still goes to Spam.')
    wrapper.unmount()
  })
})
