import 'fake-indexeddb/auto'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import type { ChangePage, SyncedMessage } from '@tresso/core'
import { deleteMailDatabase, MailDatabase } from '../db'

const USER = 'user-1'

function page(changes: Partial<ChangePage> & { cursor: number }): ChangePage {
  return { hasMore: false, mailAccounts: [], folders: [], threads: [], messages: [], outgoingMessages: [], deleted: [], ...changes }
}

const accounts = [
  { id: 'work', emailAddress: 'kim@company.example', displayName: 'Work', provider: 'imap', status: 'active' as const, statusDetail: null, lastSyncedAt: null },
  { id: 'home', emailAddress: 'kim@mail.example', displayName: 'Personal', provider: 'imap', status: 'active' as const, statusDetail: null, lastSyncedAt: null },
]
const folders = [
  { id: 'work-inbox', mailAccountId: 'work', name: 'INBOX', displayName: 'Inbox', role: 'inbox' as const },
  { id: 'work-archive', mailAccountId: 'work', name: 'Archive', displayName: 'Archive', role: 'archive' as const },
  { id: 'home-inbox', mailAccountId: 'home', name: 'INBOX', displayName: 'Inbox', role: 'inbox' as const },
]

function thread(id: string, mailAccountId: string) {
  return { id, mailAccountId, subject: `Subject ${id}`, messageCount: 1, lastMessageAt: '2026-10-19T08:00:00+00:00' }
}

function message(id: string, threadId: string, mailAccountId: string, folderIds: string[], receivedAt: string, extra: Partial<SyncedMessage> = {}): SyncedMessage {
  return {
    id,
    mailAccountId,
    threadId,
    folderIds,
    subject: `Subject ${threadId}`,
    from: { address: `${id}@sender.example`, name: `Sender ${id}` },
    recipients: { to: [], cc: [], bcc: [], replyTo: [] },
    snippet: `Snippet ${id}`,
    sentAt: null,
    receivedAt,
    flags: { seen: false, flagged: false, answered: false, draft: false },
    hasAttachments: false,
    categoryId: null,
    verdict: null,
    summary: null,
    ...extra,
  }
}

describe('MailDatabase', () => {
  let db: MailDatabase

  beforeEach(async () => {
    db = await MailDatabase.open(USER)
    await db.apply(
      page({
        cursor: 10,
        mailAccounts: accounts,
        folders,
        threads: [thread('t1', 'work'), thread('t2', 'work'), thread('t3', 'home')],
        messages: [
          message('m1', 't1', 'work', ['work-inbox'], '2026-10-19T08:00:00+00:00', { flags: { seen: true, flagged: false, answered: false, draft: false } }),
          message('m2', 't1', 'work', ['work-inbox'], '2026-10-19T09:00:00+02:00'),
          message('m3', 't2', 'work', ['work-archive'], '2026-10-19T10:00:00+00:00'),
          message('m4', 't3', 'home', ['home-inbox'], '2026-10-19T06:00:00+00:00', { flags: { seen: true, flagged: true, answered: false, draft: false } }),
        ],
      }),
    )
  })

  afterEach(async () => {
    db.close()
    await deleteMailDatabase(USER)
  })

  it('stores the cursor with the page', async () => {
    expect(await db.cursor()).toBe(10)
  })

  it('lists inbox threads of all accounts, newest first, without archived ones', async () => {
    const inbox = await db.inbox(null, 50)

    expect(inbox.map((c) => c.threadId)).toEqual(['t1', 't3'])
    expect(inbox[0]).toMatchObject({ messageCount: 2, unreadCount: 1, snippet: 'Snippet m1', unreadInbox: 1 })
    expect(inbox[0]!.senders.map((s) => s.address)).toEqual(['m1@sender.example', 'm2@sender.example'])
    expect(inbox[1]).toMatchObject({ flagged: true, unreadInbox: 0 })
  })

  it('lists one account and honors the limit', async () => {
    expect((await db.inbox('home', 50)).map((c) => c.threadId)).toEqual(['t3'])
    expect(await db.inbox(null, 1)).toHaveLength(1)
  })

  it('counts unread inbox threads per account', async () => {
    expect(Object.fromEntries(await db.unreadInboxCounts())).toEqual({ work: 1, home: 0 })
  })

  it('moves a thread out of the inbox when its messages are archived', async () => {
    await db.apply(
      page({
        cursor: 11,
        messages: [
          message('m1', 't1', 'work', ['work-archive'], '2026-10-19T08:00:00+00:00'),
          message('m2', 't1', 'work', ['work-archive'], '2026-10-19T09:00:00+02:00'),
        ],
      }),
    )

    expect((await db.inbox(null, 50)).map((c) => c.threadId)).toEqual(['t3'])
  })

  it('updates both threads when a message moves to another thread', async () => {
    await db.apply(page({ cursor: 11, messages: [message('m2', 't3', 'work', ['work-inbox'], '2026-10-19T09:00:00+02:00')] }))

    const inbox = await db.inbox(null, 50)
    expect(inbox.find((c) => c.threadId === 't1')).toMatchObject({ messageCount: 1, unreadCount: 0 })
    expect(inbox.find((c) => c.threadId === 't3')).toMatchObject({ messageCount: 2 })
  })

  it('drops deleted messages and threads', async () => {
    await db.apply(page({ cursor: 11, deleted: [{ type: 'message', id: 'm2' }] }))
    expect((await db.inbox(null, 50)).find((c) => c.threadId === 't1')).toMatchObject({ messageCount: 1 })

    await db.apply(page({ cursor: 12, deleted: [{ type: 'message', id: 'm1' }, { type: 'thread', id: 't1' }] }))
    expect((await db.inbox(null, 50)).map((c) => c.threadId)).toEqual(['t3'])
    expect(await db.thread('t1')).toBeNull()
  })

  it('removes everything of a deleted account', async () => {
    await db.apply(page({ cursor: 11, deleted: [{ type: 'mailAccount', id: 'work' }] }))

    expect((await db.mailAccounts()).map((a) => a.id)).toEqual(['home'])
    expect((await db.inbox(null, 50)).map((c) => c.threadId)).toEqual(['t3'])
    expect(await db.thread('t1')).toBeNull()
  })

  it('reacts to a folder becoming the inbox', async () => {
    await db.apply(page({ cursor: 11, folders: [{ ...folders[1]!, role: 'inbox' }] }))

    expect((await db.inbox('work', 50)).map((c) => c.threadId)).toEqual(['t2', 't1'])
  })

  it('returns a thread with its messages oldest first, comparing instants rather than local times', async () => {
    const result = await db.thread('t1')

    expect(result?.messages.map((m) => m.id)).toEqual(['m2', 'm1'])
  })

  it('keeps outgoing mail that is not settled, newest first', async () => {
    const outgoing = (id: string, status: 'scheduled' | 'sent' | 'failed', sendAt: string) => ({
      id,
      mailAccountId: 'work',
      status,
      subject: id,
      recipients: { to: [{ address: 'anna@finance.example', name: null }], cc: [], bcc: [] },
      sendAt,
      sentAt: null,
      lastError: null,
      inReplyToMessageId: null,
    })
    await db.apply(page({ cursor: 11, outgoingMessages: [outgoing('o1', 'scheduled', '2026-10-20T08:00:00Z'), outgoing('o2', 'sent', '2026-10-19T08:00:00Z'), outgoing('o3', 'failed', '2026-10-21T08:00:00Z')] }))

    expect((await db.outbox()).map((o) => o.id)).toEqual(['o3', 'o1'])

    await db.apply(page({ cursor: 12, deleted: [{ type: 'outgoingMessage', id: 'o3' }] }))
    expect((await db.outbox()).map((o) => o.id)).toEqual(['o1'])

    await db.apply(page({ cursor: 13, deleted: [{ type: 'mailAccount', id: 'work' }] }))
    expect(await db.outbox()).toEqual([])
  })

  it('suggests the people the user exchanged mail with, most recent first', async () => {
    const contacts = await db.suggestContacts('sender', 10)
    expect(contacts.map((c) => c.address)).toEqual(['m3@sender.example', 'm1@sender.example', 'm2@sender.example', 'm4@sender.example'])

    expect((await db.suggestContacts('m4', 10)).map((c) => c.address)).toEqual(['m4@sender.example'])
    expect((await db.suggestContacts('Sender m1', 10)).length).toBe(0)
    expect((await db.suggestContacts('', 2)).length).toBe(2)
  })

  it('keeps drafts on this device', async () => {
    const draft = { id: 'd1', mailAccountId: 'work', to: [], cc: [], bcc: [], subject: 'Hi', html: '<p>Hi</p>', inReplyToMessageId: null, kind: 'new' as const, outgoingId: null, noticedStatus: null, updatedAt: 1 }
    await db.saveDraft(draft)
    await db.saveDraft({ ...draft, id: 'd2', updatedAt: 2 })

    expect((await db.drafts()).map((d) => d.id)).toEqual(['d2', 'd1'])
    await db.deleteDraft('d2')
    expect(await db.draft('d2')).toBeNull()
  })

  it('keeps data per user and deletes it on request', async () => {
    db.close()
    await deleteMailDatabase(USER)
    db = await MailDatabase.open(USER)

    expect(await db.cursor()).toBe(0)
    expect(await db.mailAccounts()).toEqual([])
  })
})
