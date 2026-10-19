import 'fake-indexeddb/auto'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { ChangePage, SyncedMessage } from '@tresso/core'
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

const account = { id: 'work', emailAddress: 'kim@company.example', displayName: 'Kim', provider: 'imap', status: 'active' as const, statusDetail: null, lastSyncedAt: null, defaultSignatureId: null }
const inbox = { id: 'inbox', mailAccountId: 'work', name: 'INBOX', displayName: 'Inbox', role: 'inbox' as const }

function page(cursor: number, extra: Partial<ChangePage> = {}): ChangePage {
  return { cursor, hasMore: false, mailAccounts: [], folders: [], threads: [], messages: [], outgoingMessages: [], signatures: [], drafts: [], deleted: [], ...extra }
}

function message(id: string, receivedAt: string, verdict: SyncedMessage['verdict'] = 'do'): SyncedMessage {
  return {
    id,
    mailAccountId: 'work',
    threadId: `t-${id}`,
    folderIds: ['inbox'],
    subject: `Subject ${id}`,
    from: { address: `${id}@example.test`, name: `Sender ${id}` },
    recipients: { to: [], cc: [], bcc: [], replyTo: [] },
    snippet: '',
    sentAt: null,
    receivedAt,
    flags: { seen: false, flagged: false, answered: false, draft: false },
    hasAttachments: false,
    categoryId: null,
    verdict,
    summary: null,
  }
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
    // Mail that was already there when the app started, judged and unread: no notification for it.
    pages = [page(1, { mailAccounts: [account], folders: [inbox], messages: [message('waiting', new Date().toISOString())] })]
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

  it('stays quiet about the mail that was there at start, then tells about new mail that needs the user', async () => {
    expect(shown).toEqual([])

    pages.push(page(2, { messages: [message('new', new Date().toISOString())] }))
    await useMailStore().syncNow()

    await vi.waitFor(() => expect(shown).toEqual([[{ title: 'Sender new', body: 'Subject new' }]]))
  })

  it('stays quiet while the user is looking at Tresso', async () => {
    focused = true
    pages.push(page(2, { messages: [message('new', new Date().toISOString())] }))
    await useMailStore().syncNow()
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(shown).toEqual([])
  })

  it('stays quiet about old mail fetched late and mail that does not need the user', async () => {
    pages.push(page(2, { messages: [message('history', '2024-01-01T08:00:00Z'), message('newsletter', new Date().toISOString(), 'know')] }))
    await useMailStore().syncNow()
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(shown).toEqual([])
  })
})
