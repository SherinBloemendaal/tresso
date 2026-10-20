import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { MissingFolderError } from '../actions'
import type { ChangePage, SyncedMessage, SyncedOutgoingMessage, SyncedSmartInbox, SyncedThread, SyncedTodo } from '../sync/types'
import type { Draft, MailStore } from './mailStore'

/** Test data for the mail stores. */
export function page(changes: Partial<ChangePage> & { cursor: number }): ChangePage {
  return { hasMore: false, mailAccounts: [], folders: [], threads: [], messages: [], outgoingMessages: [], signatures: [], drafts: [], smartInboxes: [], calendars: [], calendarEvents: [], notifications: [], eventNotes: [], todos: [], deleted: [], ...changes }
}

export const accounts = [
  { id: 'work', emailAddress: 'kim@company.example', displayName: 'Work', provider: 'imap', status: 'active' as const, statusDetail: null, lastSyncedAt: null, defaultSignatureId: 'sig-work', calendarStatus: 'unknown' as const, calendarStatusDetail: null },
  { id: 'home', emailAddress: 'kim@mail.example', displayName: 'Personal', provider: 'imap', status: 'active' as const, statusDetail: null, lastSyncedAt: null, defaultSignatureId: null, calendarStatus: 'unknown' as const, calendarStatusDetail: null },
]

export const folders = [
  { id: 'work-inbox', mailAccountId: 'work', name: 'INBOX', displayName: 'Inbox', role: 'inbox' as const },
  { id: 'work-archive', mailAccountId: 'work', name: 'Archive', displayName: 'Archive', role: 'archive' as const },
  { id: 'home-inbox', mailAccountId: 'home', name: 'INBOX', displayName: 'Inbox', role: 'inbox' as const },
]

export function signature(id: string, name: string) {
  return { id, name, html: `<p>${name}</p>`, updatedAt: '2026-10-19T08:00:00+00:00' }
}

export function thread(id: string, mailAccountId: string, classification: Partial<Pick<SyncedThread, 'smartInboxId' | 'verdict' | 'classificationReason'>> = {}): SyncedThread {
  return { id, mailAccountId, subject: `Subject ${id}`, messageCount: 1, lastMessageAt: '2026-10-19T08:00:00+00:00', smartInboxId: null, verdict: null, classificationReason: null, ...classification }
}

export function smartInbox(id: string, builtin: SyncedSmartInbox['builtin'], position: number, verdict: SyncedSmartInbox['verdict'] = 'know'): SyncedSmartInbox {
  return { id, builtin, name: id, instructions: '', includeSenders: [], excludeSenders: [], verdict, autoCreateTodo: false, position }
}

export function todo(id: string, extra: Partial<SyncedTodo> = {}): SyncedTodo {
  return {
    id,
    title: `Todo ${id}`,
    notes: '',
    dueDate: null,
    dueTime: null,
    durationMinutes: null,
    completedAt: null,
    position: 0,
    source: 'manual',
    messageId: null,
    threadId: null,
    createdAt: '2026-10-19T08:00:00+00:00',
    updatedAt: '2026-10-19T08:00:00+00:00',
    ...extra,
  }
}

export function message(id: string, threadId: string, mailAccountId: string, folderIds: string[], receivedAt: string, extra: Partial<SyncedMessage> = {}): SyncedMessage {
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
    attachmentScan: null,
    smartInboxId: null,
    verdict: null,
    summary: null,
    calendarMethod: null,
    ...extra,
  }
}

export function draft(id: string, extra: Partial<Draft> = {}): Draft {
  return {
    id,
    mailAccountId: 'work',
    to: [],
    cc: [],
    bcc: [],
    subject: 'Hi',
    html: '<p>Hi</p>',
    signatureId: null,
    quoteHtml: '',
    inReplyToMessageId: null,
    kind: 'new',
    version: 0,
    pendingSave: false,
    remoteVersion: null,
    outgoingId: null,
    noticedStatus: null,
    updatedAt: 1,
    attachments: [],
    ...extra,
  }
}

/** The first page of every test: two accounts, an archived thread, read and unread mail. */
export const firstPage = page({
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
})

/**
 * What every mail store must do, whatever holds the mail: the browser's IndexedDB store and the SQLite store run the
 * same tests. `open` gives an empty store; `remove` closes and deletes it.
 */
export function describeMailStore(name: string, open: () => Promise<MailStore>, remove: (store: MailStore) => Promise<void>): void {
  describe(`${name}: the mail store`, () => {
    let db: MailStore

    beforeEach(async () => {
      db = await open()
      await db.apply(firstPage)
    })

    afterEach(async () => {
      await remove(db)
    })

    it('stores the cursor with the page', async () => {
      expect(await db.cursor()).toBe(10)
    })

    it('lists inbox threads of all accounts, newest first, without archived ones', async () => {
      const inbox = await db.inbox(null, 50)

      expect(inbox.map((c) => c.threadId)).toEqual(['t1', 't3'])
      expect(inbox[0]).toMatchObject({ messageCount: 2, unreadCount: 1, snippet: 'Snippet m1', inbox: true })
      expect(inbox[0]!.senders.map((s) => s.address)).toEqual(['m1@sender.example', 'm2@sender.example'])
      expect(inbox[1]).toMatchObject({ flagged: true, unreadCount: 0, inbox: true })
    })

    it('marks a thread once an attachment of it is blocked', async () => {
      expect((await db.inbox(null, 50)).map((c) => c.attachmentsBlocked)).toEqual([false, false])

      await db.apply(
        page({ cursor: 11, messages: [message('m2', 't1', 'work', ['work-inbox'], '2026-10-19T09:00:00+02:00', { hasAttachments: true, attachmentScan: 'infected' })] }),
      )

      expect((await db.inbox(null, 50)).find((c) => c.threadId === 't1')).toMatchObject({ hasAttachments: true, attachmentsBlocked: true })
    })

    it('lists one account and honors the limit', async () => {
      expect((await db.inbox('home', 50)).map((c) => c.threadId)).toEqual(['t3'])
      expect(await db.inbox(null, 1)).toHaveLength(1)
    })

    it('counts unread inbox threads per account, listing accounts without any', async () => {
      expect(Object.fromEntries(await db.unreadInboxCounts())).toEqual({ work: 1, home: 0 })
    })

    it('moves a thread out of the inbox when its messages are archived', async () => {
      await db.apply(
        page({
          cursor: 11,
          messages: [message('m1', 't1', 'work', ['work-archive'], '2026-10-19T08:00:00+00:00'), message('m2', 't1', 'work', ['work-archive'], '2026-10-19T09:00:00+02:00')],
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

    it('removes everything of a deleted account, but keeps its drafts without an account', async () => {
      await db.saveDraft(draft('d1', { mailAccountId: 'work', pendingSave: true }))

      await db.apply(page({ cursor: 11, deleted: [{ type: 'mailAccount', id: 'work' }] }))

      expect((await db.mailAccounts()).map((a) => a.id)).toEqual(['home'])
      expect((await db.inbox(null, 50)).map((c) => c.threadId)).toEqual(['t3'])
      expect(await db.thread('t1')).toBeNull()
      expect(await db.messagesByThread(['t1'])).toEqual(new Map([['t1', []]]))
      expect(await db.draft('d1')).toMatchObject({ mailAccountId: null, pendingSave: true })
    })

    it('drops a deleted folder from the lists', async () => {
      await db.apply(page({ cursor: 11, deleted: [{ type: 'folder', id: 'home-inbox' }] }))

      expect((await db.folders()).map((f) => f.id).sort()).toEqual(['work-archive', 'work-inbox'])
      expect((await db.inbox(null, 50)).map((c) => c.threadId)).toEqual(['t1'])
    })

    it('reacts to a folder becoming the inbox', async () => {
      await db.apply(page({ cursor: 11, folders: [{ ...folders[1]!, role: 'inbox' }] }))

      expect((await db.inbox('work', 50)).map((c) => c.threadId)).toEqual(['t2', 't1'])
    })

    it('returns a thread with its messages oldest first, comparing instants rather than local times', async () => {
      const result = await db.thread('t1')

      expect(result?.messages.map((m) => m.id)).toEqual(['m2', 'm1'])
    })

    it('finds inbox threads beyond the loaded list, and what a selection holds', async () => {
      expect(await db.listThreadIds({ kind: 'inbox', mailAccountId: null }, () => true)).toEqual(['t1', 't3'])
      expect(await db.listThreadIds({ kind: 'inbox', mailAccountId: 'work' }, (c) => c.unreadCount > 0)).toEqual(['t1'])

      expect(await db.describeThreads({ kind: 'inbox', mailAccountId: null }, ['t1', 't2', 't3', 'gone'])).toEqual({
        present: ['t1', 't3'],
        accounts: ['work', 'home'],
        senders: ['m1@sender.example', 'm2@sender.example', 'm4@sender.example'],
      })
      expect((await db.describeThreads({ kind: 'inbox', mailAccountId: 'home' }, ['t1', 't3'])).present).toEqual(['t3'])
    })

    it('lists the messages of threads', async () => {
      expect(await db.messagesByThread(['t1', 't3', 'gone'])).toEqual(
        new Map([
          ['t1', ['m1', 'm2']],
          ['t3', ['m4']],
          ['gone', []],
        ]),
      )
      expect(await db.messageIdsOfThreads(['t1', 't2'])).toEqual(['m1', 'm2', 'm3'])
    })

    it('breaks ties the same way: the larger id first', async () => {
      const at = '2026-10-19T12:00:00+00:00'
      await db.apply(
        page({
          cursor: 11,
          threads: [thread('ta', 'work'), thread('tb', 'work')],
          messages: [message('ma', 'ta', 'work', ['work-inbox'], at), message('mb', 'tb', 'work', ['work-inbox'], at)],
        }),
      )
      await db.saveDraft(draft('da', { updatedAt: 5 }))
      await db.saveDraft(draft('db', { updatedAt: 5 }))

      expect((await db.inbox(null, 2)).map((c) => c.threadId)).toEqual(['tb', 'ta'])
      expect((await db.listThreadIds({ kind: 'inbox', mailAccountId: 'work' }, () => true)).slice(0, 2)).toEqual(['tb', 'ta'])
      expect((await db.drafts()).map((d) => d.id)).toEqual(['db', 'da'])
      expect((await db.suggestContacts('m', 2)).map((c) => c.key)).toEqual(['mb@sender.example', 'ma@sender.example'])
    })

    it('lists the messages of a thread asked for twice once', async () => {
      expect(await db.messageIdsOfThreads(['t1', 't1'])).toEqual(['m1', 'm2'])
    })

    it('keeps outgoing mail that is not settled, newest first', async () => {
      const outgoing = (id: string, status: SyncedOutgoingMessage['status'], sendAt: string): SyncedOutgoingMessage => ({
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
      expect((await db.outgoing('o2'))?.status).toBe('sent')

      await db.apply(page({ cursor: 12, deleted: [{ type: 'outgoingMessage', id: 'o3' }] }))
      expect((await db.outbox()).map((o) => o.id)).toEqual(['o1'])
      expect(await db.outgoing('o3')).toBeNull()

      await db.apply(page({ cursor: 13, deleted: [{ type: 'mailAccount', id: 'work' }] }))
      expect(await db.outbox()).toEqual([])
    })

    it('suggests the people the user exchanged mail with, most recent first', async () => {
      const contacts = await db.suggestContacts('sender', 10)
      expect(contacts.map((c) => c.address)).toEqual(['m3@sender.example', 'm1@sender.example', 'm2@sender.example', 'm4@sender.example'])

      expect((await db.suggestContacts('m4', 10)).map((c) => c.address)).toEqual(['m4@sender.example'])
      expect((await db.suggestContacts('Sender m1', 10)).length).toBe(0)
      expect((await db.suggestContacts('', 2)).length).toBe(2)
      expect(await db.suggestContacts('', 0)).toEqual([])
    })

    it('counts every message with a contact once and keeps the newest name', async () => {
      await db.apply(
        page({
          cursor: 11,
          messages: [
            message('m5', 't1', 'work', ['work-inbox'], '2026-10-19T12:00:00+00:00', { from: { address: 'M1@Sender.example', name: 'Newer name' } }),
            message('m6', 't1', 'work', ['work-inbox'], '2026-10-18T12:00:00+00:00', { from: { address: 'm1@sender.example', name: 'Older name' } }),
          ],
        }),
      )
      // A message the delta brings again is not counted twice.
      await db.apply(page({ cursor: 12, messages: [message('m5', 't1', 'work', ['work-archive'], '2026-10-19T12:00:00+00:00', { from: { address: 'M1@Sender.example', name: 'Newer name' } })] }))

      expect(await db.suggestContacts('m1@', 10)).toEqual([{ key: 'm1@sender.example', address: 'M1@Sender.example', name: 'Newer name', lastSeen: Date.parse('2026-10-19T12:00:00+00:00'), messages: 3 }])
    })

    it('keeps drafts on this device, most recently changed first', async () => {
      await db.saveDraft(draft('d1', { updatedAt: 1 }))
      await db.saveDraft(draft('d2', { updatedAt: 2 }))

      expect((await db.drafts()).map((d) => d.id)).toEqual(['d2', 'd1'])
      await db.deleteDraft('d2')
      expect(await db.draft('d2')).toBeNull()
      expect(await db.draft('d1')).toEqual(draft('d1', { updatedAt: 1 }))
    })

    it('changes a draft as one step, or leaves it as it is', async () => {
      await db.saveDraft(draft('d1', { subject: 'First' }))

      expect(await db.changeDraft('d1', (stored) => stored && { ...stored, subject: `${stored.subject}, changed` })).toMatchObject({ subject: 'First, changed' })
      expect(await db.draft('d1')).toMatchObject({ subject: 'First, changed' })
      expect(await db.changeDraft('d1', () => null)).toMatchObject({ subject: 'First, changed' })
      expect(await db.changeDraft('gone', (stored) => stored)).toBeNull()
      expect(await db.draft('gone')).toBeNull()

      // Changes made at the same time each see the one before.
      await Promise.all([1, 2, 3].map((n) => db.changeDraft('d1', (stored) => stored && { ...stored, subject: `${stored.subject} ${n}` })))
      expect((await db.draft('d1'))!.subject).toBe('First, changed 1 2 3')
    })

    it('keeps a sent draft until its outcome is known, and leaves unsaved edits alone', async () => {
      const synced = { id: 'd1', mailAccountId: 'work', kind: 'new' as const, to: [], cc: [], bcc: [], subject: 'Server', html: '', signatureId: null, quoteHtml: '', inReplyToMessageId: null, version: 3, updatedAt: '2026-10-19T08:00:00Z', attachments: [] }
      await db.apply(page({ cursor: 11, drafts: [synced] }))
      expect(await db.draft('d1')).toMatchObject({ subject: 'Server', version: 3, pendingSave: false, updatedAt: Date.parse('2026-10-19T08:00:00Z') })

      await db.saveDraft({ ...(await db.draft('d1'))!, subject: 'Local edit', pendingSave: true })
      await db.apply(page({ cursor: 12, drafts: [{ ...synced, subject: 'Newer elsewhere', version: 4 }] }))
      expect(await db.draft('d1')).toMatchObject({ subject: 'Local edit', remoteVersion: 4 })

      await db.apply(page({ cursor: 13, deleted: [{ type: 'draft', id: 'd1' }] }))
      expect(await db.draft('d1')).toMatchObject({ subject: 'Local edit', pendingSave: true })

      await db.saveDraft({ ...(await db.draft('d1'))!, pendingSave: false, outgoingId: 'o1' })
      await db.apply(page({ cursor: 14, deleted: [{ type: 'draft', id: 'd1' }] }))
      // The server keeps a draft being sent, version and all, so an undo can go on writing it.
      expect(await db.draft('d1')).toMatchObject({ outgoingId: 'o1', version: 3, pendingSave: false })

      await db.saveDraft({ ...(await db.draft('d1'))!, outgoingId: null })
      await db.apply(page({ cursor: 15, deleted: [{ type: 'draft', id: 'd1' }] }))
      expect(await db.draft('d1')).toBeNull()
    })

    it("takes the server's files for a draft, also while its text has edits not uploaded yet", async () => {
      const file = { id: 'f1', filename: 'notes.txt', contentType: 'text/plain', size: 10, scanStatus: 'pending' as const }
      const synced = { id: 'd1', mailAccountId: 'work', kind: 'new' as const, to: [], cc: [], bcc: [], subject: 'Server', html: '', signatureId: null, quoteHtml: '', inReplyToMessageId: null, version: 2, updatedAt: '2026-10-19T08:00:00Z', attachments: [file] }
      await db.saveDraft(draft('d1', { subject: 'Typed here', version: 2, pendingSave: true }))

      await db.apply(page({ cursor: 11, drafts: [synced] }))

      expect(await db.draft('d1')).toMatchObject({ subject: 'Typed here', pendingSave: true, remoteVersion: null, attachments: [file] })
    })

    it('reads drafts stored before they had files as having none', async () => {
      const { attachments: _, ...old } = draft('d1')
      await db.saveDraft(old as Draft)

      expect((await db.draft('d1'))?.attachments).toEqual([])
      expect((await db.drafts())[0]?.attachments).toEqual([])
    })

    it('hands out new unread inbox mail judged do or delegate, once', async () => {
      db.takeArrivals()
      await db.apply(
        page({
          cursor: 11,
          threads: [thread('t4', 'work')],
          messages: [
            message('m5', 't4', 'work', ['work-inbox'], '2026-10-19T11:00:00+00:00', { verdict: 'do' }),
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

      expect((await db.thread('t1'))?.messages.find((m) => m.id === 'm2')?.folderIds).toEqual(['work-inbox'])
      expect((await db.thread('t2'))?.messages[0]).toMatchObject({ folderIds: ['work-inbox'], flags: { seen: true } })
      expect((await db.inbox('work', 10)).map((c) => c.threadId).sort()).toEqual(['t1', 't2'])
    })

    it('lists signatures by name and keeps them current', async () => {
      expect((await db.signatures()).map((s) => s.name)).toEqual(['Short reply', 'Work'])

      await db.apply(page({ cursor: 11, signatures: [{ ...signature('sig-work', 'Work'), html: '<p>Kim Visser</p>' }] }))
      expect((await db.signatures()).find((s) => s.id === 'sig-work')?.html).toBe('<p>Kim Visser</p>')

      await db.apply(page({ cursor: 12, deleted: [{ type: 'signature', id: 'sig-work' }], mailAccounts: [{ ...accounts[0]!, defaultSignatureId: null, calendarStatus: 'unknown' as const, calendarStatusDetail: null }] }))
      expect((await db.signatures()).map((s) => s.id)).toEqual(['sig-short'])
      expect((await db.mailAccounts()).find((a) => a.id === 'work')?.defaultSignatureId).toBeNull()
      expect(await db.cursor()).toBe(12)
    })

    describe('todos', () => {
      it('lists open todos in order, then done ones, most recently done first', async () => {
        await db.apply(
          page({
            cursor: 30,
            todos: [
              todo('done-early', { completedAt: '2026-10-18T08:00:00+00:00' }),
              todo('second', { position: 2 }),
              todo('done-late', { completedAt: '2026-10-19T08:00:00+00:00' }),
              todo('first', { position: 1, dueDate: '2026-10-24', dueTime: '17:00', durationMinutes: 30 }),
            ],
          }),
        )

        expect((await db.todos()).map((t) => t.id)).toEqual(['first', 'second', 'done-late', 'done-early'])
        expect(await db.todo('first')).toEqual(todo('first', { position: 1, dueDate: '2026-10-24', dueTime: '17:00', durationMinutes: 30 }))
        expect(await db.todo('gone')).toBeNull()
        expect(await db.cursor()).toBe(30)
      })

      it('keeps todos current and drops deleted ones', async () => {
        await db.apply(page({ cursor: 30, todos: [todo('a'), todo('b', { position: 1 })] }))

        await db.apply(page({ cursor: 31, todos: [todo('a', { title: 'Call Anna', completedAt: '2026-10-20T08:00:00+00:00' })], deleted: [{ type: 'todo', id: 'b' }] }))

        expect((await db.todos()).map((t) => [t.id, t.title, t.completedAt])).toEqual([['a', 'Call Anna', '2026-10-20T08:00:00+00:00']])
      })

      it('finds the todos of a thread, following a message that moved to another thread', async () => {
        await db.apply(
          page({
            cursor: 30,
            todos: [
              todo('from-m1', { messageId: 'm1', threadId: 't1' }),
              todo('from-m3', { messageId: 'm3', threadId: 't1', position: 1 }),
              todo('elsewhere', { messageId: 'm4', threadId: 't3' }),
              todo('email-gone', { messageId: 'm9', threadId: 't1', position: 2 }),
              todo('own'),
            ],
          }),
        )

        // m3 is in t2 on this device, so its todo goes with t2.
        expect((await db.todosOfThread('t1')).map((t) => t.id)).toEqual(['from-m1', 'email-gone'])
        expect((await db.todosOfThread('t2')).map((t) => t.id)).toEqual(['from-m3'])
        expect(await db.todosOfThread('t9')).toEqual([])
      })

      it('keeps todos when the account of their email goes', async () => {
        await db.apply(page({ cursor: 30, todos: [todo('from-m4', { messageId: 'm4', threadId: 't3' })] }))

        await db.apply(page({ cursor: 31, deleted: [{ type: 'mailAccount', id: 'home' }] }))

        expect((await db.todos()).map((t) => t.id)).toEqual(['from-m4'])
        expect(await db.messages(['m4'])).toEqual([])
      })
    })

    it('finds messages by id in the order asked for, leaving out the ones it does not have', async () => {
      expect((await db.messages(['m3', 'gone', 'm1', 'm3'])).map((m) => m.id)).toEqual(['m3', 'm1', 'm3'])
    })

    it('lists accounts by name', async () => {
      expect((await db.mailAccounts()).map((a) => a.displayName)).toEqual(['Personal', 'Work'])
    })

    describe('smart inboxes', () => {
      beforeEach(async () => {
        await db.apply(
          page({
            cursor: 20,
            folders: [{ id: 'work-junk', mailAccountId: 'work', name: 'Junk', displayName: 'Junk', role: 'spam' }],
            smartInboxes: [smartInbox('spam', 'spam', 4, 'none'), smartInbox('todo', 'todo', 0, 'do'), smartInbox('news', 'advertise', 3)],
            threads: [
              thread('t1', 'work', { smartInboxId: 'todo', verdict: 'do', classificationReason: 'direct' }),
              thread('t2', 'work', { smartInboxId: 'news', verdict: 'know', classificationReason: 'bulk' }),
              thread('t3', 'home', { smartInboxId: 'spam', verdict: 'none', classificationReason: 'server_spam' }),
              thread('t5', 'work', { smartInboxId: 'spam', verdict: 'none', classificationReason: 'server_spam' }),
              thread('t6', 'work', { smartInboxId: 'news', verdict: 'know', classificationReason: 'bulk' }),
            ],
            messages: [
              message('m5', 't5', 'work', ['work-junk'], '2026-10-19T11:00:00+00:00'),
              message('m6', 't6', 'work', ['work-inbox'], '2026-10-19T12:00:00+00:00', { flags: { seen: true, flagged: false, answered: false, draft: false } }),
            ],
          }),
        )
      })

      it('keeps them in sidebar order and drops deleted ones', async () => {
        expect((await db.smartInboxes()).map((s) => s.id)).toEqual(['todo', 'news', 'spam'])

        await db.apply(page({ cursor: 21, deleted: [{ type: 'smartInbox', id: 'news' }] }))
        expect((await db.smartInboxes()).map((s) => s.id)).toEqual(['todo', 'spam'])
      })

      it('lists a smart inbox across accounts, newest first, without archived conversations', async () => {
        expect((await db.list({ kind: 'smartInbox', smartInboxId: 'news' }, 10)).map((c) => c.threadId)).toEqual(['t6'])
        expect((await db.list({ kind: 'smartInbox', smartInboxId: 'spam' }, 1)).map((c) => c.threadId)).toEqual(['t5'])
      })

      it('shows spam from inboxes and junk folders in the Spam smart inbox only', async () => {
        expect((await db.list({ kind: 'smartInbox', smartInboxId: 'spam' }, 10)).map((c) => c.threadId)).toEqual(['t5', 't3'])
        expect((await db.inbox(null, 10)).map((c) => c.threadId)).toEqual(['t6', 't1'])
        expect(Object.fromEntries(await db.unreadInboxCounts())).toEqual({ work: 1, home: 0 })
      })

      it('counts unread conversations per smart inbox', async () => {
        expect(Object.fromEntries(await db.unreadSmartInboxCounts())).toEqual({ todo: 1, news: 0, spam: 1 })
      })

      it('carries the classification on the conversation', async () => {
        expect((await db.inbox(null, 10)).find((c) => c.threadId === 't1')).toMatchObject({ smartInboxId: 'todo', verdict: 'do', classificationReason: 'direct', inbox: true, active: true })
      })

      it('finds and describes the conversations of a smart inbox for selections', async () => {
        const scope = { kind: 'smartInbox' as const, smartInboxId: 'spam' }

        expect(await db.listThreadIds(scope, () => true)).toEqual(['t5', 't3'])
        expect(await db.describeThreads(scope, ['t1', 't3', 't5'])).toEqual({ present: ['t3', 't5'], accounts: ['home', 'work'], senders: ['m4@sender.example', 'm5@sender.example'] })
      })

      it('moves a conversation at once when the user classifies it, and puts it back when that fails', async () => {
        const previous = await db.classifyThread('t3', 'todo', 'delegate')

        expect((await db.list({ kind: 'smartInbox', smartInboxId: 'todo' }, 10)).map((c) => [c.threadId, c.verdict, c.classificationReason])).toEqual([
          ['t1', 'do', 'direct'],
          ['t3', 'delegate', 'user'],
        ])
        expect((await db.inbox(null, 10)).map((c) => c.threadId)).toContain('t3')

        await db.restoreThread(previous!, { smartInboxId: 'todo', verdict: 'delegate' })
        expect((await db.thread('t3'))?.thread).toMatchObject({ smartInboxId: 'spam', classificationReason: 'server_spam' })
        expect((await db.inbox(null, 10)).map((c) => c.threadId)).not.toContain('t3')
      })

      it('does not put back a conversation the delta changed since', async () => {
        const previous = await db.classifyThread('t2', 'todo', 'do')
        await db.apply(page({ cursor: 21, threads: [thread('t2', 'work', { smartInboxId: 'news', verdict: 'know', classificationReason: 'sender_rule' })] }))

        await db.restoreThread(previous!, { smartInboxId: 'todo', verdict: 'do' })

        expect((await db.thread('t2'))?.thread).toMatchObject({ smartInboxId: 'news', classificationReason: 'sender_rule' })
      })

      it('keeps a placement the server confirmed, also when the answer to the request was lost', async () => {
        const previous = await db.classifyThread('t2', 'todo', 'do')
        await db.apply(page({ cursor: 21, threads: [thread('t2', 'work', { smartInboxId: 'todo', verdict: 'do', classificationReason: 'user' })] }))

        await db.restoreThread(previous!, { smartInboxId: 'todo', verdict: 'do' })

        expect((await db.thread('t2'))?.thread).toMatchObject({ smartInboxId: 'todo', verdict: 'do', classificationReason: 'user' })
      })

      it('says when the conversation to classify is gone', async () => {
        expect(await db.classifyThread('gone', 'todo', 'do')).toBeNull()
      })
    })
  })
}
