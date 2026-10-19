import { beforeEach, describe, expect, it } from 'vitest'
import { MissingFolderError, type ChangePage, type SyncedMessage } from '@tresso/core'
import { CopyClosingError, MailDatabase, SCHEMA_VERSION } from '../mailDatabase'
import type { Sql } from '../sql'
import { memorySql } from './sqljs'

function page(changes: Partial<ChangePage> & { cursor: number }): ChangePage {
  return { hasMore: false, mailAccounts: [], folders: [], threads: [], messages: [], outgoingMessages: [], signatures: [], drafts: [], deleted: [], ...changes }
}

const accounts = [
  { id: 'work', emailAddress: 'kim@company.example', displayName: 'Work', provider: 'imap', status: 'active' as const, statusDetail: null, lastSyncedAt: null, defaultSignatureId: null },
  { id: 'home', emailAddress: 'kim@mail.example', displayName: 'Personal', provider: 'imap', status: 'active' as const, statusDetail: null, lastSyncedAt: null, defaultSignatureId: null },
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

const seen = { seen: true, flagged: false, answered: false, draft: false }

describe('MailDatabase on the device', () => {
  let sql: Sql
  let db: MailDatabase

  beforeEach(async () => {
    sql = await memorySql()
    db = await MailDatabase.open(sql)
    await db.apply(
      page({
        cursor: 10,
        mailAccounts: accounts,
        folders,
        threads: [thread('t1', 'work'), thread('t2', 'work'), thread('t3', 'home')],
        messages: [
          message('m1', 't1', 'work', ['work-inbox'], '2026-10-19T08:00:00+00:00', { flags: seen }),
          message('m2', 't1', 'work', ['work-inbox'], '2026-10-19T09:00:00+02:00'),
          message('m3', 't2', 'work', ['work-archive'], '2026-10-19T10:00:00+00:00'),
          message('m4', 't3', 'home', ['home-inbox'], '2026-10-19T06:00:00+00:00', { flags: { ...seen, flagged: true } }),
        ],
      }),
    )
  })

  it('stores the cursor with the page', async () => {
    expect(await db.cursor()).toBe(10)
  })

  it('lists inbox threads of all accounts, newest first, without archived ones', async () => {
    const inbox = await db.inbox(null, 10)

    expect(inbox.map((c) => c.threadId)).toEqual(['t1', 't3'])
    expect(inbox[0]).toMatchObject({ messageCount: 2, unreadCount: 1, snippet: 'Snippet m1', inbox: true })
    expect(inbox[1]).toMatchObject({ flagged: true, senders: [{ address: 'm4@sender.example', name: 'Sender m4' }] })
  })

  it('lists one account, honors the limit and counts unread inbox threads', async () => {
    await db.apply(page({ cursor: 11, messages: [message('m5', 't3', 'home', ['home-inbox'], '2026-10-19T07:00:00+00:00')] }))

    expect((await db.inbox('home', 10)).map((c) => c.threadId)).toEqual(['t3'])
    expect((await db.inbox(null, 1)).map((c) => c.threadId)).toEqual(['t1'])
    expect(await db.unreadInboxCounts()).toEqual(new Map([['home', 1], ['work', 1]]))
  })

  it('moves a thread out of the inbox when its messages are archived', async () => {
    await db.apply(
      page({
        cursor: 11,
        messages: [message('m1', 't1', 'work', ['work-archive'], '2026-10-19T08:00:00+00:00', { flags: seen }), message('m2', 't1', 'work', ['work-archive'], '2026-10-19T09:00:00+02:00')],
      }),
    )

    expect((await db.inbox(null, 10)).map((c) => c.threadId)).toEqual(['t3'])
  })

  it('updates both threads when a message moves to another thread', async () => {
    await db.apply(page({ cursor: 11, messages: [message('m2', 't2', 'work', ['work-inbox'], '2026-10-19T09:00:00+02:00')] }))

    expect((await db.thread('t1'))?.messages.map((m) => m.id)).toEqual(['m1'])
    expect((await db.inbox(null, 10)).find((c) => c.threadId === 't2')).toMatchObject({ messageCount: 2, unreadCount: 2 })
  })

  it('drops deleted messages and threads, and everything of a deleted account', async () => {
    await db.apply(page({ cursor: 11, deleted: [{ type: 'message', id: 'm2' }, { type: 'thread', id: 't2' }, { type: 'mailAccount', id: 'home' }] }))

    expect((await db.thread('t1'))?.messages.map((m) => m.id)).toEqual(['m1'])
    expect(await db.thread('t2')).toBeNull()
    expect(await db.thread('t3')).toBeNull()
    expect((await db.mailAccounts()).map((a) => a.id)).toEqual(['work'])
    expect((await db.folders()).map((f) => f.id).sort()).toEqual(['work-archive', 'work-inbox'])
    expect((await db.inbox(null, 10)).map((c) => c.threadId)).toEqual(['t1'])
  })

  it('reacts to a folder becoming the inbox and to a deleted folder', async () => {
    await db.apply(page({ cursor: 11, folders: [{ ...folders[1]!, role: 'inbox' }] }))
    expect((await db.inbox('work', 10)).map((c) => c.threadId)).toEqual(['t2', 't1'])

    await db.apply(page({ cursor: 12, deleted: [{ type: 'folder', id: 'work-inbox' }] }))
    expect((await db.inbox('work', 10)).map((c) => c.threadId)).toEqual(['t2'])
  })

  it('returns a thread with its messages oldest first, comparing instants', async () => {
    expect((await db.thread('t1'))?.messages.map((m) => m.id)).toEqual(['m2', 'm1'])
  })

  it('stores a page completely or not at all', async () => {
    const broken = page({ cursor: 11, messages: [message('m9', 't1', 'work', ['work-inbox'], '2026-10-19T11:00:00+00:00'), { ...message('m10', 't1', 'work', [], 'x'), threadId: null as unknown as string }] })

    await expect(db.apply(broken)).rejects.toThrow()

    expect(await db.cursor()).toBe(10)
    expect((await db.thread('t1'))?.messages.map((m) => m.id)).toEqual(['m2', 'm1'])
  })

  it('shows actions at once and takes archived mail out of the inbox list', async () => {
    const applied = await db.applyAction(['m1', 'm2'], 'archive')

    expect(applied.after.map((m) => m.folderIds)).toEqual([['work-archive'], ['work-archive']])
    expect((await db.inbox('work', 10)).map((c) => c.threadId)).toEqual([])
    await db.applyAction(['m4'], 'flag')
    expect((await db.thread('t3'))?.messages[0]?.flags.flagged).toBe(true)
  })

  it('says when there is nothing to trash into, and changes nothing', async () => {
    await expect(db.applyAction(['m4'], 'trash')).rejects.toEqual(new MissingFolderError('kim@mail.example', 'Trash'))
    expect((await db.thread('t3'))?.messages[0]?.folderIds).toEqual(['home-inbox'])
  })

  it('undoes an action the server refused, except where the server has spoken since', async () => {
    const applied = await db.applyAction(['m2', 'm3'], 'move', 'work-archive')
    await db.apply(page({ cursor: 11, messages: [message('m3', 't2', 'work', ['work-inbox'], '2026-10-19T10:00:00+00:00', { flags: seen })] }))

    await db.revertAction(applied)

    expect((await db.thread('t1'))?.messages.find((m) => m.id === 'm2')?.folderIds).toEqual(['work-inbox'])
    expect((await db.thread('t2'))?.messages[0]).toMatchObject({ folderIds: ['work-inbox'], flags: { seen: true } })
  })

  it('stores a page and an action that arrive together one after the other, as the device allows one writer', async () => {
    await Promise.all([
      db.apply(page({ cursor: 11, messages: [message('m5', 't1', 'work', ['work-inbox'], '2026-10-19T11:00:00+00:00')] })),
      db.applyAction(['m4'], 'flag'),
      db.resyncFromStart(),
    ])

    expect(await db.cursor()).toBe(0)
    expect((await db.thread('t1'))?.messages.map((m) => m.id)).toContain('m5')
  })

  it('refuses pages once closing, after storing the one in progress', async () => {
    const storing = db.apply(page({ cursor: 11, messages: [message('m5', 't1', 'work', ['work-inbox'], '2026-10-19T11:00:00+00:00')] }))
    await db.shut()
    await storing

    expect(await db.cursor()).toBe(11)
    await expect(db.apply(page({ cursor: 12 }))).rejects.toBeInstanceOf(CopyClosingError)
  })

  it('syncs from the start after a resync, even when a pull under way stores a page meanwhile, and after a restart', async () => {
    // A pull that began from the stored cursor, before the resync was asked for.
    expect(await db.cursor()).toBe(10)
    await db.resyncFromStart()
    await db.apply(page({ cursor: 11 }))

    expect(await (await MailDatabase.open(sql)).cursor()).toBe(0)
    expect(await db.cursor()).toBe(0)
    await db.apply(page({ cursor: 11, hasMore: true }))
    expect(await db.cursor()).toBe(11)
    expect(await (await MailDatabase.open(sql)).cursor()).toBe(11)
  })

  it('keeps a resync asked for while a pull from the start stores its first page', async () => {
    await db.resyncFromStart()
    expect(await db.cursor()).toBe(0)

    await Promise.all([db.apply(page({ cursor: 11 })), db.resyncFromStart()])

    expect(await db.cursor()).toBe(0)
    expect(await (await MailDatabase.open(sql)).cursor()).toBe(0)
  })

  it('rebuilds a copy made by another version, so it syncs again from the start and has the current shape', async () => {
    await sql.run("UPDATE meta SET value = '0' WHERE key = 'schema'")
    // An older shape of a table.
    await sql.exec('DROP TABLE conversations; CREATE TABLE conversations (thread_id TEXT PRIMARY KEY NOT NULL)')

    const reopened = await MailDatabase.open(sql)

    expect(await reopened.cursor()).toBe(0)
    expect(await reopened.inbox(null, 10)).toEqual([])
    expect(await sql.first<{ value: string }>("SELECT value FROM meta WHERE key = 'schema'")).toEqual({ value: String(SCHEMA_VERSION) })
    await reopened.apply(page({ cursor: 1, mailAccounts: accounts, folders, threads: [thread('t1', 'work')], messages: [message('m1', 't1', 'work', ['work-inbox'], '2026-10-19T08:00:00+00:00')] }))
    expect((await reopened.inbox(null, 10)).map((c) => c.threadId)).toEqual(['t1'])
  })
})
