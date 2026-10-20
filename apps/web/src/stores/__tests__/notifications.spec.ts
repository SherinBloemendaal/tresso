import 'fake-indexeddb/auto'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { ChangePage, SyncedMailNotification } from '@tresso/core'
import { deleteMailDatabase } from '@/mail/db'
import type { MailNotification } from '@/mail/mailNotifications'

const shown = vi.hoisted(() => [] as MailNotification[][])

vi.mock('@/platform/notifier', () => ({
  notifier: {
    show: async (notifications: MailNotification[]) => {
      shown.push(notifications)
    },
  },
}))

const { useMailStore } = await import('../mail')

const account = { id: 'work', emailAddress: 'kim@company.example', displayName: 'Kim', provider: 'imap', status: 'active' as const, statusDetail: null, lastSyncedAt: null, defaultSignatureId: null, calendarStatus: 'unknown' as const, calendarStatusDetail: null }
const inbox = { id: 'inbox', mailAccountId: 'work', name: 'INBOX', displayName: 'Inbox', role: 'inbox' as const }

function page(cursor: number, extra: Partial<ChangePage> = {}): ChangePage {
  return { cursor, hasMore: false, mailAccounts: [], folders: [], threads: [], messages: [], outgoingMessages: [], signatures: [], drafts: [], smartInboxes: [], calendars: [], calendarEvents: [], notifications: [], eventNotes: [], todos: [], mailNotifications: [], handOvers: [], deleted: [], ...extra }
}

function delivered(id: string, extra: Partial<SyncedMailNotification> = {}): SyncedMailNotification {
  return { id, messageId: `m-${id}`, threadId: `t-${id}`, title: `Sender ${id}`, body: `Subject ${id}`, deliveredAt: new Date().toISOString(), channels: ['phone', 'desktop'], silent: false, ...extra }
}

describe('notifications for new mail', () => {
  let pages: ChangePage[]
  let focused: boolean

  beforeEach(async () => {
    sessionStorage.setItem('auth.tokens', JSON.stringify({ accessToken: 'token', refreshToken: null, expiresAt: Date.now() + 3_600_000 }))
    setActivePinia(createPinia())
    shown.length = 0
    focused = false
    vi.spyOn(document, 'hasFocus').mockImplementation(() => focused)
    // Delivered before the app started (the delta brings the last week): no notification for it.
    pages = [page(1, { mailAccounts: [account], folders: [inbox], mailNotifications: [delivered('waiting')] })]
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => {
        const url = new URL(String(input))
        if (url.pathname === '/api/v1/sync/changes') {
          const since = Number(url.searchParams.get('since'))
          return new Response(JSON.stringify(pages.find((p) => p.cursor > since) ?? page(since)))
        }
        return new Response(JSON.stringify({ detail: 'unavailable' }), { status: 503 })
      }),
    )
    await useMailStore().start('notify-user')
  })

  afterEach(async () => {
    await useMailStore().stop()
    await deleteMailDatabase('notify-user')
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
    sessionStorage.clear()
  })

  it('stays quiet about what was delivered before the start, then shows what the server delivers to the desktop', async () => {
    expect(shown).toEqual([])

    pages.push(page(2, { mailNotifications: [delivered('new')] }))
    await useMailStore().syncNow()

    await vi.waitFor(() => expect(shown).toEqual([[{ title: 'Sender new', body: 'Subject new', path: '/thread/t-new', silent: false }]]))
  })

  it('stays quiet while the user is looking at Tresso', async () => {
    focused = true
    pages.push(page(2, { mailNotifications: [delivered('new')] }))
    await useMailStore().syncNow()
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(shown).toEqual([])
  })

  it('stays quiet about old notifications and those the user did not want on the desktop', async () => {
    pages.push(page(2, { mailNotifications: [delivered('history', { deliveredAt: '2024-01-01T08:00:00Z' }), delivered('phone', { channels: ['phone'] })] }))
    await useMailStore().syncNow()
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(shown).toEqual([])
  })

  it('shows a notification once, also when a later page brings it again', async () => {
    pages.push(page(2, { mailNotifications: [delivered('new')] }))
    await useMailStore().syncNow()
    await vi.waitFor(() => expect(shown).toHaveLength(1))

    pages.push(page(3, { mailNotifications: [delivered('new')] }))
    await useMailStore().syncNow()
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(shown).toHaveLength(1)
  })
})
