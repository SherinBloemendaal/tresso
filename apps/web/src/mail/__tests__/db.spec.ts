import 'fake-indexeddb/auto'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import type { ChangePage, SyncedMessage } from '@tresso/core'
import { openDB } from 'idb'
import { MissingFolderError } from '@tresso/core'
import { databaseName, deleteMailDatabase, MailDatabase } from '../db'

const USER = 'user-1'

function page(changes: Partial<ChangePage> & { cursor: number }): ChangePage {
  return { hasMore: false, mailAccounts: [], folders: [], threads: [], messages: [], outgoingMessages: [], signatures: [], drafts: [], deleted: [], ...changes }
}

const accounts = [
  { id: 'work', emailAddress: 'kim@company.example', displayName: 'Work', provider: 'imap', status: 'active' as const, statusDetail: null, lastSyncedAt: null, defaultSignatureId: 'sig-work' },
  { id: 'home', emailAddress: 'kim@mail.example', displayName: 'Personal', provider: 'imap', status: 'active' as const, statusDetail: null, lastSyncedAt: null, defaultSignatureId: null },
]
const folders = [
  { id: 'work-inbox', mailAccountId: 'work', name: 'INBOX', displayName: 'Inbox', role: 'inbox' as const },
  { id: 'work-archive', mailAccountId: 'work', name: 'Archive', displayName: 'Archive', role: 'archive' as const },
  { id: 'home-inbox', mailAccountId: 'home', name: 'INBOX', displayName: 'Inbox', role: 'inbox' as const },
]

function signature(id: string, name: string) {
  return { id, name, html: `<p>${name}</p>`, updatedAt: '2026-10-19T08:00:00+00:00' }
}

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
        signatures: [signature('sig-work', 'Work'), signature('sig-short', 'Short reply')],
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
    const draft = { id: 'd1', mailAccountId: 'work', to: [], cc: [], bcc: [], subject: 'Hi', html: '<p>Hi</p>', signatureId: null, quoteHtml: '', inReplyToMessageId: null, kind: 'new' as const, version: 0, pendingSave: false, remoteVersion: null, outgoingId: null, noticedStatus: null, updatedAt: 1 }
    await db.saveDraft(draft)
    await db.saveDraft({ ...draft, id: 'd2', updatedAt: 2 })

    expect((await db.drafts()).map((d) => d.id)).toEqual(['d2', 'd1'])
    await db.deleteDraft('d2')
    expect(await db.draft('d2')).toBeNull()
  })

  it('keeps a sent draft until its outcome is known, and leaves unsaved edits alone', async () => {
    const synced = { id: 'd1', mailAccountId: 'work', kind: 'new' as const, to: [], cc: [], bcc: [], subject: 'Server', html: '', signatureId: null, quoteHtml: '', inReplyToMessageId: null, version: 3, updatedAt: '2026-10-19T08:00:00Z' }
    await db.apply(page({ cursor: 11, drafts: [synced] }))
    expect(await db.draft('d1')).toMatchObject({ subject: 'Server', version: 3, pendingSave: false })

    await db.saveDraft({ ...(await db.draft('d1'))!, subject: 'Local edit', pendingSave: true })
    await db.apply(page({ cursor: 12, drafts: [{ ...synced, subject: 'Newer elsewhere', version: 4 }] }))
    expect(await db.draft('d1')).toMatchObject({ subject: 'Local edit', remoteVersion: 4 })

    await db.saveDraft({ ...(await db.draft('d1'))!, pendingSave: false, outgoingId: 'o1' })
    await db.apply(page({ cursor: 13, deleted: [{ type: 'draft', id: 'd1' }] }))
    expect(await db.draft('d1')).toMatchObject({ outgoingId: 'o1', version: 0 })

    await db.saveDraft({ ...(await db.draft('d1'))!, outgoingId: null })
    await db.apply(page({ cursor: 14, deleted: [{ type: 'draft', id: 'd1' }] }))
    expect(await db.draft('d1')).toBeNull()
  })

  it('hands out new unread inbox mail judged do or delegate, once', async () => {
    db.takeArrivals()
    const work = { flags: { seen: false, flagged: false, answered: false, draft: false } }
    await db.apply(
      page({
        cursor: 11,
        threads: [thread('t4', 'work')],
        messages: [
          message('m5', 't4', 'work', ['work-inbox'], '2026-10-19T11:00:00+00:00', { ...work, verdict: 'do' }),
          message('m6', 't4', 'work', ['work-inbox'], '2026-10-19T11:01:00+00:00', { verdict: 'delegate' }),
          message('m7', 't4', 'work', ['work-inbox'], '2026-10-19T11:02:00+00:00', { verdict: 'know' }),
          message('m8', 't4', 'work', ['work-inbox'], '2026-10-19T11:03:00+00:00', { verdict: null }),
          message('m9', 't4', 'work', ['work-archive'], '2026-10-19T11:04:00+00:00', { verdict: 'do' }),
          message('m10', 't4', 'work', ['work-inbox'], '2026-10-19T11:05:00+00:00', { verdict: 'do', flags: { seen: true, flagged: false, answered: false, draft: false } }),
        ],
      }),
    )

    expect(db.takeArrivals()).toEqual([
      { messageId: 'm5', threadId: 't4', mailAccountId: 'work', from: { address: 'm5@sender.example', name: 'Sender m5' }, subject: 'Subject t4', snippet: 'Snippet m5', receivedAt: '2026-10-19T11:00:00+00:00', verdict: 'do' },
      { messageId: 'm6', threadId: 't4', mailAccountId: 'work', from: { address: 'm6@sender.example', name: 'Sender m6' }, subject: 'Subject t4', snippet: 'Snippet m6', receivedAt: '2026-10-19T11:01:00+00:00', verdict: 'delegate' },
    ])
    expect(db.takeArrivals()).toEqual([])

    // Classified later: the unjudged message counts once it is judged; flag changes on judged ones do not repeat it.
    await db.apply(
      page({
        cursor: 12,
        messages: [
          message('m8', 't4', 'work', ['work-inbox'], '2026-10-19T11:03:00+00:00', { verdict: 'do' }),
          message('m5', 't4', 'work', ['work-inbox'], '2026-10-19T11:00:00+00:00', { verdict: 'do', flags: { seen: false, flagged: true, answered: false, draft: false } }),
          message('m6', 't4', 'work', ['work-inbox'], '2026-10-19T11:01:00+00:00', { verdict: 'do' }),
        ],
      }),
    )
    expect(db.takeArrivals().map((arrival) => arrival.messageId)).toEqual(['m8'])
  })

  it('shows actions at once and takes archived mail out of the inbox list', async () => {
    const applied = await db.applyAction(['m1', 'm2'], 'archive')

    expect(applied.after.map((m) => m.folderIds)).toEqual([['work-archive'], ['work-archive']])
    expect((await db.inbox('work', 10)).map((c) => c.threadId)).toEqual([])
    await db.applyAction(['m1', 'm2'], 'flag')
    expect((await db.thread('t1'))?.messages.map((m) => m.flags.flagged)).toEqual([true, true])
  })

  it('archives into All Mail where there is no Archive folder, and says when there is neither', async () => {
    await db.apply(page({ cursor: 11, folders: [{ id: 'home-all', mailAccountId: 'home', name: '[Gmail]/All Mail', displayName: 'All Mail', role: 'all' }] }))
    expect((await db.applyAction(['m4'], 'archive')).after[0]?.folderIds).toEqual(['home-all'])

    await expect(db.applyAction(['m4'], 'trash')).rejects.toEqual(new MissingFolderError('kim@mail.example', 'Trash'))
    expect((await db.thread('t3'))?.messages[0]?.folderIds).toEqual(['home-all'])
  })

  it('undoes an action the server refused, except where the server has spoken since', async () => {
    const applied = await db.applyAction(['m2', 'm3'], 'move', 'work-archive')
    // The delta brought a newer version of m3 in the meantime.
    await db.apply(page({ cursor: 11, messages: [message('m3', 't2', 'work', ['work-inbox'], '2026-10-19T10:00:00+00:00', { flags: { seen: true, flagged: false, answered: false, draft: false } })] }))

    await db.revertAction(applied)

    const thread = await db.thread('t1')
    expect(thread?.messages.find((m) => m.id === 'm2')?.folderIds).toEqual(['work-inbox'])
    expect((await db.thread('t2'))?.messages[0]).toMatchObject({ folderIds: ['work-inbox'], flags: { seen: true } })
    expect((await db.inbox('work', 10)).map((c) => c.threadId).sort()).toEqual(['t1', 't2'])
  })

  it('keeps data per user and deletes it on request', async () => {
    db.close()
    await deleteMailDatabase(USER)
    db = await MailDatabase.open(USER)

    expect(await db.cursor()).toBe(0)
    expect(await db.mailAccounts()).toEqual([])
  })

  it('lists signatures by name and keeps them current', async () => {
    expect((await db.signatures()).map((s) => s.name)).toEqual(['Short reply', 'Work'])

    await db.apply(page({ cursor: 11, signatures: [{ ...signature('sig-work', 'Work'), html: '<p>Kim Visser</p>' }] }))
    expect((await db.signatures()).find((s) => s.id === 'sig-work')?.html).toBe('<p>Kim Visser</p>')

    await db.apply(
      page({ cursor: 12, deleted: [{ type: 'signature', id: 'sig-work' }], mailAccounts: [{ ...accounts[0]!, defaultSignatureId: null }] }),
    )
    expect((await db.signatures()).map((s) => s.id)).toEqual(['sig-short'])
    expect((await db.mailAccounts()).find((a) => a.id === 'work')?.defaultSignatureId).toBeNull()
    expect(await db.cursor()).toBe(12)
  })
})

describe('MailDatabase upgrade', () => {
  afterEach(async () => {
    await deleteMailDatabase(USER)
  })

  it('adds signatures to a copy stored before they existed and syncs it again from the start', async () => {
    const old = await openDB(databaseName(USER), 1, {
      upgrade(database) {
        database.createObjectStore('meta')
        database.createObjectStore('mailAccounts', { keyPath: 'id' })
        database.createObjectStore('folders', { keyPath: 'id' }).createIndex('byAccount', 'mailAccountId')
        database.createObjectStore('threads', { keyPath: 'id' }).createIndex('byAccount', 'mailAccountId')
        const messages = database.createObjectStore('messages', { keyPath: 'id' })
        messages.createIndex('byThread', 'threadId')
        messages.createIndex('byAccount', 'mailAccountId')
        messages.createIndex('byFolder', 'folderIds', { multiEntry: true })
        const conversations = database.createObjectStore('conversations', { keyPath: 'threadId' })
        conversations.createIndex('byAccount', 'mailAccountId')
        conversations.createIndex('byInboxDate', ['inbox', 'lastMessageAt'])
        conversations.createIndex('byAccountInboxDate', ['mailAccountId', 'inbox', 'lastMessageAt'])
        conversations.createIndex('byAccountUnreadInbox', ['mailAccountId', 'unreadInbox'])
      },
    })
    const { defaultSignatureId: _, ...withoutDefault } = accounts[0]!
    await old.put('mailAccounts', withoutDefault)
    await old.put('meta', 7, 'cursor')
    old.close()

    const db = await MailDatabase.open(USER)
    try {
      // Outgoing mail, contacts and signatures only arrive through the delta, so the copy starts over.
      expect(await db.cursor()).toBe(0)
      expect(await db.signatures()).toEqual([])
      expect(await db.mailAccounts()).toEqual([{ ...withoutDefault, defaultSignatureId: null }])
      await db.apply(page({ cursor: 8, signatures: [signature('sig-work', 'Work')] }))
      expect((await db.signatures()).map((s) => s.id)).toEqual(['sig-work'])
    } finally {
      db.close()
    }
  })
})
