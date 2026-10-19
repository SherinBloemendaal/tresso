import 'fake-indexeddb/auto'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { ChangePage, SyncedMessage, SyncedOutgoingMessage } from '@tresso/core'
import { deleteMailDatabase } from '@/mail/db'
import { UNDO_SECONDS, useComposeStore } from '../compose'
import { useMailStore } from '../mail'

const original: SyncedMessage = {
  id: 'm1',
  mailAccountId: 'work',
  threadId: 't1',
  folderIds: ['inbox'],
  subject: 'Q4 budget',
  from: { address: 'anna@finance.example', name: 'Anna' },
  recipients: { to: [{ address: 'kim@company.example', name: 'Kim' }], cc: [{ address: 'pieter@company.example', name: null }], bcc: [], replyTo: [] },
  snippet: 'Approve?',
  sentAt: null,
  receivedAt: '2026-10-19T08:00:00Z',
  flags: { seen: true, flagged: false, answered: false, draft: false },
  hasAttachments: false,
  categoryId: null,
  verdict: null,
  summary: null,
}

function outgoing(status: SyncedOutgoingMessage['status'], lastError: string | null = null): SyncedOutgoingMessage {
  return { id: 'o1', mailAccountId: 'work', status, subject: 'Re: Q4 budget', recipients: { to: [{ address: 'anna@finance.example', name: 'Anna' }], cc: [], bcc: [] }, sendAt: new Date().toISOString(), sentAt: null, lastError, inReplyToMessageId: 'm1' }
}

function page(cursor: number, extra: Partial<ChangePage> = {}): ChangePage {
  return { cursor, hasMore: false, mailAccounts: [], folders: [], threads: [], messages: [], outgoingMessages: [], signatures: [], drafts: [], deleted: [], ...extra }
}

describe('compose store', () => {
  let pages: ChangePage[]
  const requests: { method: string; path: string; body: unknown }[] = []

  beforeEach(async () => {
    sessionStorage.setItem('auth.tokens', JSON.stringify({ accessToken: 'token', refreshToken: null, expiresAt: Date.now() + 3_600_000 }))
    setActivePinia(createPinia())
    requests.length = 0
    pages = [
      page(1, {
        mailAccounts: [
          { id: 'work', emailAddress: 'kim@company.example', displayName: 'Kim', provider: 'imap', status: 'active', statusDetail: null, lastSyncedAt: null, defaultSignatureId: 's1' },
        ],
        signatures: [{ id: 's1', name: 'Work', html: '<p>Kim Visser</p>', updatedAt: '2026-10-19T08:00:00Z' }],
      }),
    ]
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = new URL(String(input))
        const method = init?.method ?? 'GET'
        requests.push({ method, path: url.pathname, body: init?.body ? JSON.parse(String(init.body)) : undefined })
        const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
        if (url.pathname === '/api/v1/sync/changes') {
          const since = Number(url.searchParams.get('since'))
          return json(pages.find((p) => p.cursor > since) ?? page(since))
        }
        if (url.pathname === '/api/v1/messages/m1/body') return json({ id: 'm1', html: '<p>Can you approve?</p>', text: null, hasRemoteContent: false, remoteContentLoaded: false, attachments: [] })
        if (url.pathname === '/api/v1/outgoing-messages') return json(outgoing('scheduled'), 201)
        if (url.pathname === '/api/v1/outgoing-messages/o1/cancel') return json(outgoing('cancelled'))
        return json({ detail: 'unavailable' }, 503)
      }),
    )
    await useMailStore().start('compose-user')
  })

  afterEach(async () => {
    await useMailStore().stop()
    await deleteMailDatabase('compose-user')
    vi.unstubAllGlobals()
    vi.useRealTimers()
    sessionStorage.clear()
  })

  it('prefills a reply all with recipients, subject and the quoted original', async () => {
    const compose = useComposeStore()

    await compose.openReply(original, 'replyAll')

    expect(compose.draft).toMatchObject({ kind: 'replyAll', mailAccountId: 'work', subject: 'Re: Q4 budget', inReplyToMessageId: 'm1' })
    expect(compose.draft?.to.map((a) => a.address)).toEqual(['anna@finance.example'])
    expect(compose.draft?.cc.map((a) => a.address)).toEqual(['pieter@company.example'])
    expect(compose.draft?.html).toBe('')
    expect(compose.draft?.quoteHtml).toContain('<blockquote><p>Can you approve?</p></blockquote>')
  })

  it('sends the text, then the default signature, then the quote', async () => {
    const compose = useComposeStore()
    await compose.openReply(original, 'reply')
    expect(compose.draft?.signatureId).toBe('s1')
    compose.update({ html: '<p>Approved.</p>' })

    await compose.send()

    const post = requests.find((r) => r.method === 'POST' && r.path === '/api/v1/outgoing-messages')
    const html = (post?.body as { html?: string } | undefined)?.html ?? ''
    expect(html.indexOf('<p>Approved.</p>')).toBe(0)
    expect(html.indexOf('<p>Kim Visser</p>')).toBeGreaterThan(0)
    expect(html.indexOf('<p>Kim Visser</p>')).toBeLessThan(html.indexOf('<blockquote>'))
  })

  it('refuses to send without recipients', async () => {
    const compose = useComposeStore()
    compose.openNew()

    await compose.send()

    expect(compose.error).toBe('Add at least one recipient.')
    expect(requests.some((r) => r.method === 'POST')).toBe(false)
  })

  it('sends after the undo window and brings the draft back on undo', async () => {
    const compose = useComposeStore()
    await compose.openReply(original, 'reply')
    const before = Date.now()

    await compose.send()
    expect(compose.error).toBeNull()

    const post = requests.find((r) => r.method === 'POST' && r.path === '/api/v1/outgoing-messages')
    if (post === undefined) throw new Error('Nothing was posted.')
    expect(post.body).toMatchObject({ mailAccountId: 'work', subject: 'Re: Q4 budget', inReplyToMessageId: 'm1', to: [{ address: 'anna@finance.example', name: 'Anna' }] })
    const sendAt = Date.parse((post.body as { sendAt: string }).sendAt)
    expect(sendAt - before).toBeGreaterThanOrEqual(UNDO_SECONDS * 1000 - 50)
    expect(compose.draft).toBeNull()
    expect(compose.undoable?.outgoingId).toBe('o1')

    await compose.undo()

    expect(requests.some((r) => r.path === '/api/v1/outgoing-messages/o1/cancel')).toBe(true)
    expect(compose.draft).toMatchObject({ subject: 'Re: Q4 budget', outgoingId: null })
  })

  it('announces a failure once and removes the draft once sent', async () => {
    const compose = useComposeStore()
    const mail = useMailStore()
    await compose.openReply(original, 'reply')
    await compose.send()
    const draftId = (await mail.drafts())[0]!.id

    pages.push(page(2, { outgoingMessages: [outgoing('failed', 'The outgoing mail server refused the recipient.')] }))
    await mail.syncNow()
    await compose.followUp()
    await compose.followUp()

    const failures = compose.notices.filter((n) => n.tone === 'error')
    expect(failures).toHaveLength(1)
    expect(failures[0]!.text).toBe('"Re: Q4 budget" was not sent. The outgoing mail server refused the recipient.')
    expect(await mail.draft(draftId)).not.toBeNull()
    expect(await compose.draftFor('o1')).not.toBeNull()

    pages.push(page(3, { outgoingMessages: [outgoing('sent')] }))
    await mail.syncNow()
    await compose.followUp()

    expect(await mail.draft(draftId)).toBeNull()
  })
})
