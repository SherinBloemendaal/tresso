import 'fake-indexeddb/auto'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { MAX_MESSAGES_PER_ACTION, type ChangePage, type SyncedMessage } from '@tresso/core'
import { deleteMailDatabase } from '@/mail/db'
import { useMailStore } from '../mail'

const account = { id: 'work', emailAddress: 'kim@company.example', displayName: 'Work', provider: 'imap', status: 'active' as const, statusDetail: null, lastSyncedAt: null, defaultSignatureId: null, calendarStatus: 'unknown' as const, calendarStatusDetail: null }

function message(id: string): SyncedMessage {
  return {
    id,
    mailAccountId: 'work',
    threadId: `t-${id}`,
    folderIds: ['inbox'],
    subject: '',
    from: { address: 'a@example.test', name: null },
    recipients: { to: [], cc: [], bcc: [], replyTo: [] },
    snippet: '',
    sentAt: null,
    receivedAt: '2026-10-19T08:00:00Z',
    flags: { seen: false, flagged: false, answered: false, draft: false },
    hasAttachments: false,
    attachmentScan: null,
    smartInboxId: null,
    verdict: null,
    summary: null,
    calendarMethod: null,
  }
}

describe('message actions in large selections', () => {
  const ids = Array.from({ length: MAX_MESSAGES_PER_ACTION + 2 }, (_, i) => `m${i}`)
  let batches: number

  beforeEach(async () => {
    sessionStorage.setItem('auth.tokens', JSON.stringify({ accessToken: 'token', refreshToken: null, expiresAt: Date.now() + 3_600_000 }))
    setActivePinia(createPinia())
    batches = 0
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => {
        const url = new URL(String(input))
        if (url.pathname === '/api/v1/sync/changes') {
          const since = Number(url.searchParams.get('since'))
          const first: ChangePage = {
            cursor: 1,
            hasMore: false,
            mailAccounts: [account],
            folders: [{ id: 'inbox', mailAccountId: 'work', name: 'INBOX', displayName: 'Inbox', role: 'inbox' }],
            threads: ids.map((id) => ({ id: `t-${id}`, mailAccountId: 'work', subject: '', messageCount: 1, lastMessageAt: '2026-10-19T08:00:00Z', smartInboxId: null, verdict: null, classificationReason: null })),
            messages: ids.map(message),
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
          return new Response(JSON.stringify(since === 0 ? first : { ...first, cursor: since, mailAccounts: [], folders: [], threads: [], messages: [] }))
        }
        if (url.pathname === '/api/v1/message-actions') {
          batches++
          return batches === 1 ? new Response(JSON.stringify({ changed: MAX_MESSAGES_PER_ACTION })) : new Response(JSON.stringify({ detail: 'Try again later.' }), { status: 503 })
        }
        return new Response('{}', { status: 404 })
      }),
    )
    await useMailStore().start('batch-user')
  })

  afterEach(async () => {
    await useMailStore().stop()
    await deleteMailDatabase('batch-user')
    vi.unstubAllGlobals()
    sessionStorage.clear()
  })

  it('sends batches and, when one fails, puts back only the messages the server did not take', async () => {
    const mail = useMailStore()

    await expect(mail.act(ids, 'markRead')).rejects.toThrow('Try again later.')

    expect(batches).toBe(2)
    const seen = async (id: string) => (await mail.thread(`t-${id}`))?.messages[0]?.flags.seen
    expect(await seen('m0')).toBe(true)
    expect(await seen(`m${MAX_MESSAGES_PER_ACTION - 1}`)).toBe(true)
    expect(await seen(`m${MAX_MESSAGES_PER_ACTION}`)).toBe(false)
    expect(await seen(`m${MAX_MESSAGES_PER_ACTION + 1}`)).toBe(false)
  })
})
