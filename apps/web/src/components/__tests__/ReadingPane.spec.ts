import 'fake-indexeddb/auto'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import type { ChangePage, SyncedMessage } from '@tresso/core'
import { deleteMailDatabase } from '@/mail/db'
import { useComposeStore } from '@/stores/compose'
import { useMailStore } from '@/stores/mail'
import { useSelectionStore } from '@/stores/selection'
import ReadingPane from '../ReadingPane.vue'

const account = { id: 'work', emailAddress: 'kim@company.example', displayName: 'Work', provider: 'imap', status: 'active' as const, statusDetail: null, lastSyncedAt: null, defaultSignatureId: null }
const folders = [
  { id: 'inbox', mailAccountId: 'work', name: 'INBOX', displayName: 'Inbox', role: 'inbox' as const },
  { id: 'sent', mailAccountId: 'work', name: 'Sent', displayName: 'Sent', role: 'sent' as const },
  { id: 'archive', mailAccountId: 'work', name: 'Archive', displayName: 'Archive', role: 'archive' as const },
  { id: 'trash', mailAccountId: 'work', name: 'Trash', displayName: 'Trash', role: 'trash' as const },
  { id: 'projects', mailAccountId: 'work', name: 'Projects', displayName: 'Projects', role: 'custom' as const },
]

function message(id: string, folderIds: string[], receivedAt: string, seen: boolean): SyncedMessage {
  return {
    id,
    mailAccountId: 'work',
    threadId: 't1',
    folderIds,
    subject: 'Offsite plan',
    from: { address: `${id}@example.test`, name: `Sender ${id}` },
    recipients: { to: [], cc: [], bcc: [], replyTo: [] },
    snippet: '',
    sentAt: null,
    receivedAt,
    flags: { seen, flagged: false, answered: false, draft: false },
    hasAttachments: false,
    categoryId: null,
    verdict: null,
    summary: null,
  }
}

function page(cursor: number, extra: Partial<ChangePage> = {}): ChangePage {
  return { cursor, hasMore: false, mailAccounts: [], folders: [], threads: [], messages: [], outgoingMessages: [], signatures: [], drafts: [], deleted: [], ...extra }
}

async function settle(): Promise<void> {
  for (let i = 0; i < 6; i++) {
    await flushPromises()
    await new Promise((resolve) => setTimeout(resolve, 0))
  }
}

describe('ReadingPane actions', () => {
  const actions: Record<string, unknown>[] = []
  let refuse: string | null
  let later: ChangePage | null
  /** While set, marking read waits for it before the server answers. */
  let holdMarkRead: Promise<void> | null
  let router: Router

  beforeEach(async () => {
    sessionStorage.setItem('auth.tokens', JSON.stringify({ accessToken: 'token', refreshToken: null, expiresAt: Date.now() + 3_600_000 }))
    setActivePinia(createPinia())
    actions.length = 0
    refuse = null
    later = null
    holdMarkRead = null
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = new URL(String(input))
        if (url.pathname === '/api/v1/sync/changes') {
          const since = Number(url.searchParams.get('since'))
          const first = page(1, {
            mailAccounts: [account],
            folders,
            threads: [{ id: 't1', mailAccountId: 'work', subject: 'Offsite plan', messageCount: 2, lastMessageAt: '2026-10-19T09:00:00Z' }],
            messages: [message('m1', ['sent'], '2026-10-19T08:00:00Z', true), message('m2', ['inbox'], '2026-10-19T09:00:00Z', false)],
          })
          return new Response(JSON.stringify(since === 0 ? first : since === 1 && later !== null ? later : page(since)))
        }
        if (url.pathname === '/api/v1/message-actions') {
          const body = JSON.parse(String(init?.body)) as Record<string, unknown>
          actions.push(body)
          if (body.action === 'markRead' && holdMarkRead !== null) {
            await holdMarkRead
          }
          if (refuse !== null && body.action === refuse) {
            return new Response(JSON.stringify({ detail: 'action: kim@company.example has no Archive folder.' }), { status: 422 })
          }
          return new Response(JSON.stringify({ changed: 1 }))
        }
        return new Response(JSON.stringify({ detail: 'unavailable' }), { status: 503 })
      }),
    )
    await useMailStore().start('pane-user')
    router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', name: 'unified', component: { template: '<div />' } },
        { path: '/inbox/:threadId', name: 'unified-thread', component: { template: '<div />' } },
      ],
    })
    await router.push('/inbox/t1')
  })

  afterEach(async () => {
    await useMailStore().stop()
    await deleteMailDatabase('pane-user')
    vi.unstubAllGlobals()
    sessionStorage.clear()
  })

  function mountPane() {
    return mount(ReadingPane, { props: { threadId: 't1', backTo: '/' }, global: { plugins: [router] }, attachTo: document.body })
  }

  /** Waits until the pane has read the conversation and its buttons take actions again. */
  async function opened(wrapper: ReturnType<typeof mountPane>): Promise<void> {
    await vi.waitFor(() => expect(actions).toHaveLength(1))
    await vi.waitFor(() => expect(wrapper.get('button[aria-keyshortcuts="E"]').attributes('disabled')).toBeUndefined())
  }

  async function folderIdsOf(id: string): Promise<string[]> {
    const thread = await useMailStore().thread('t1')
    return thread?.messages.find((m) => m.id === id)?.folderIds ?? []
  }

  it('reads the conversation when it opens', async () => {
    mountPane()
    await vi.waitFor(() => expect(actions).toHaveLength(1))

    expect(actions).toEqual([{ messageIds: ['m2'], action: 'markRead' }])
    expect((await useMailStore().thread('t1'))?.messages.every((m) => m.flags.seen)).toBe(true)
  })

  it('archives the conversation, goes back to the list and can undo it', async () => {
    const wrapper = mountPane()
    await opened(wrapper)
    actions.length = 0

    await wrapper.get('button[aria-keyshortcuts="E"]').trigger('click')
    await vi.waitFor(() => expect(router.currentRoute.value.path).toBe('/'))

    expect(actions).toEqual([{ messageIds: ['m1', 'm2'], action: 'archive' }])
    expect(await folderIdsOf('m2')).toEqual(['archive'])
    expect(await folderIdsOf('m1')).toEqual(['sent'])
    expect(router.currentRoute.value.path).toBe('/')

    const notice = useComposeStore().notices.find((n) => n.text === 'Archived.')
    expect(notice?.action?.label).toBe('Undo')
    actions.length = 0
    notice?.action?.run()
    await vi.waitFor(() => expect(actions).toHaveLength(1))
    // Only what moved goes back, from where it went: the Sent copy was never touched.
    expect(actions).toEqual([{ messageIds: ['m2'], action: 'move', folderId: 'inbox', fromFolderId: 'archive' }])
  })

  it('takes the shortcuts, but not while typing', async () => {
    const wrapper = mountPane()
    await opened(wrapper)
    actions.length = 0

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 's' }))
    await vi.waitFor(() => expect(wrapper.get('button[aria-keyshortcuts="S"]').attributes('aria-pressed')).toBe('true'))
    expect(actions).toEqual([{ messageIds: ['m2'], action: 'flag' }])
    expect(wrapper.get('button[aria-keyshortcuts="S"]').attributes('aria-pressed')).toBe('true')

    const input = document.createElement('input')
    document.body.appendChild(input)
    input.dispatchEvent(new KeyboardEvent('keydown', { key: '#', bubbles: true }))
    await settle()
    expect(actions).toHaveLength(1)
    input.remove()

    window.dispatchEvent(new KeyboardEvent('keydown', { key: '#' }))
    await vi.waitFor(() => expect(actions).toHaveLength(2))
    expect(actions[1]).toEqual({ messageIds: ['m1', 'm2'], action: 'trash' })
    expect(await folderIdsOf('m1')).toEqual(['trash'])
  })

  it('moves to a folder of the account from a menu', async () => {
    const wrapper = mountPane()
    await opened(wrapper)
    actions.length = 0

    await wrapper.get('button[aria-haspopup="menu"]').trigger('click')
    const items = wrapper.findAll('[role="menuitem"]')
    expect(items.map((item) => item.text())).toEqual(['Archive', 'Inbox', 'Projects', 'Sent', 'Trash'])
    expect(document.activeElement).toBe(items[0]!.element)
    await wrapper.get('[role="menu"]').trigger('keydown', { key: 'ArrowDown' })
    await wrapper.get('[role="menu"]').trigger('keydown', { key: 'ArrowDown' })
    expect(document.activeElement).toBe(items[2]!.element)
    expect(actions, 'browsing the folders moves nothing').toEqual([])

    await items[2]!.trigger('click')
    await vi.waitFor(() => expect(useComposeStore().notices.some((n) => n.text === 'Moved to Projects.')).toBe(true))
    expect(actions).toEqual([{ messageIds: ['m1', 'm2'], action: 'move', folderId: 'projects' }])
  })

  it('puts back with undo what it can, and says what it cannot', async () => {
    // m2 is in Inbox and in Projects: trash takes both copies, and no move puts both back. m1 came from Sent only.
    later = page(2, { messages: [message('m2', ['inbox', 'projects'], '2026-10-19T09:00:00Z', false)] })
    await useMailStore().syncNow()
    const wrapper = mountPane()
    await opened(wrapper)
    actions.length = 0

    await wrapper.get('button[aria-keyshortcuts="#"]').trigger('click')
    await vi.waitFor(() => expect(router.currentRoute.value.path).toBe('/'))

    const notice = useComposeStore().notices.find((n) => n.text.startsWith('Moved to Trash.'))
    expect(notice?.text).toBe('Moved to Trash. Undo leaves one message that was in several folders.')
    actions.length = 0
    notice?.action?.run()
    await vi.waitFor(() => expect(actions).toEqual([{ messageIds: ['m1'], action: 'move', folderId: 'sent', fromFolderId: 'trash' }]))
  })

  it('archives when asked while the conversation is still being read', async () => {
    let release = () => {}
    holdMarkRead = new Promise((resolve) => (release = resolve))
    const wrapper = mountPane()
    await vi.waitFor(() => expect(wrapper.find('.subject').exists()).toBe(true))
    await vi.waitFor(() => expect(actions).toHaveLength(1))

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'e' }))
    await settle()
    expect(actions.map((a) => a.action), 'the archive waits for the read').toEqual(['markRead'])
    release()

    await vi.waitFor(() => expect(router.currentRoute.value.path).toBe('/'))
    expect(actions.map((a) => a.action)).toEqual(['markRead', 'archive'])
  })

  it('leaves its shortcuts to the list while conversations are selected there', async () => {
    const wrapper = mountPane()
    await opened(wrapper)
    actions.length = 0
    useSelectionStore().toggle('t9')

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 's' }))
    await settle()

    expect(actions).toEqual([])
  })

  it('ignores a held key and shortcuts meant for the compose window', async () => {
    const wrapper = mountPane()
    await opened(wrapper)
    actions.length = 0

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 's', repeat: true }))
    useComposeStore().openNew()
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 's' }))
    await settle()

    expect(actions).toEqual([])
    expect(wrapper.get('button[aria-keyshortcuts="S"]').attributes('aria-pressed')).toBe('false')
  })

  it('puts things back and says why when the server refuses', async () => {
    const wrapper = mountPane()
    await opened(wrapper)
    refuse = 'archive'

    await wrapper.get('button[aria-keyshortcuts="E"]').trigger('click')
    await vi.waitFor(() => expect(useComposeStore().notices.map((n) => n.text)).toContain('action: kim@company.example has no Archive folder.'))

    expect(await folderIdsOf('m2')).toEqual(['inbox'])
    expect(router.currentRoute.value.path).toBe('/inbox/t1')
  })
})
