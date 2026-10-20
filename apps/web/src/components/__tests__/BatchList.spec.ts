import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { h } from 'vue'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { batchTimeLabel } from '@tresso/core'
import type { Conversation } from '@/mail/db'
import ConversationList from '../ConversationList.vue'

function conversation(threadId: string, lastMessageAt: string, batchedAt: string): Conversation {
  return {
    threadId,
    mailAccountId: 'work',
    subject: threadId,
    snippet: '',
    lastMessageAt: Date.parse(lastMessageAt),
    messageCount: 1,
    unreadCount: 0,
    flagged: false,
    hasAttachments: false,
    attachmentsBlocked: false,
    senders: [{ name: 'Shop', address: 'news@shop.example' }],
    inbox: true,
    active: true,
    heldUntil: null,
    batchedAt: Date.parse(batchedAt),
    smartInboxId: null,
    verdict: null,
    classificationReason: null,
  }
}

describe('ConversationList in batches', () => {
  beforeEach(() => {
    sessionStorage.setItem('auth.tokens', JSON.stringify({ accessToken: 'token', refreshToken: null, expiresAt: Date.now() + 3_600_000 }))
    setActivePinia(createPinia())
  })

  afterEach(() => {
    sessionStorage.clear()
  })

  it('heads each batch with its time and puts what the page gives under each row', async () => {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:any(.*)', component: { template: '<div />' } }] })
    const conversations = [
      conversation('late', '2026-10-20T15:40:00Z', '2026-10-20T16:00:00Z'),
      conversation('early', '2026-10-20T15:10:00Z', '2026-10-20T16:00:00Z'),
      conversation('morning', '2026-10-20T07:30:00Z', '2026-10-20T08:00:00Z'),
    ]
    const wrapper = mount(ConversationList, {
      props: { title: 'Batches', conversations, selectedThreadId: null, hasMore: false, loading: false, hasAccounts: true, linkTo: (id: string) => `/batches/${id}`, groupBy: 'batch' },
      slots: {
        aside: () => h('p', { class: 'next' }, 'Next batch at 13:00'),
        extra: ({ conversation: row }: { conversation: Conversation }) => h('span', { class: 'under' }, `under ${row.threadId}`),
      },
      global: { plugins: [router] },
    })

    const headings = wrapper.findAll('h2.day').map((heading) => heading.text())
    expect(headings).toEqual([
      `Delivered at ${batchTimeLabel(Date.parse('2026-10-20T16:00:00Z'))}`,
      `Delivered at ${batchTimeLabel(Date.parse('2026-10-20T08:00:00Z'))}`,
    ])
    expect(wrapper.findAll('section[aria-label^="Delivered at"]').map((group) => group.findAll('.under').map((under) => under.text()))).toEqual([
      ['under late', 'under early'],
      ['under morning'],
    ])
    expect(wrapper.get('.next').text()).toBe('Next batch at 13:00')
  })
})
