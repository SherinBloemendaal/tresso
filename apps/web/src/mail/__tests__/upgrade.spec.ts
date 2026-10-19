import 'fake-indexeddb/auto'
import { describe, expect, it } from 'vitest'
import { openDB } from 'idb'
import { databaseName, deleteMailDatabase, IndexedDbMailStore } from '../db'

describe('IndexedDbMailStore upgrade to synced drafts', () => {
  it('uploads drafts that were kept on the device before', async () => {
    // A version 3 copy, as the previous build left it: drafts lived only on the device.
    const v3 = await openDB(databaseName('upgrade-drafts'), 3, {
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
        const outgoing = database.createObjectStore('outgoing', { keyPath: 'id' })
        outgoing.createIndex('byAccount', 'mailAccountId')
        outgoing.createIndex('bySendAt', 'sendAt')
        database.createObjectStore('contacts', { keyPath: 'key' }).createIndex('byLastSeen', 'lastSeen')
        database.createObjectStore('drafts', { keyPath: 'id' }).createIndex('byUpdatedAt', 'updatedAt')
        database.createObjectStore('signatures', { keyPath: 'id' })
      },
    })
    const base = { mailAccountId: null, to: [], cc: [], bcc: [], html: '', signatureId: null, quoteHtml: '', inReplyToMessageId: null, kind: 'new', noticedStatus: null, updatedAt: 1 }
    await v3.put('drafts', { ...base, id: 'written', subject: 'Written before', outgoingId: null })
    await v3.put('drafts', { ...base, id: 'sending', subject: 'Being sent', outgoingId: 'o1' })
    await v3.put('meta', 9, 'cursor')
    v3.close()

    const db = await IndexedDbMailStore.open('upgrade-drafts')

    expect(await db.draft('written')).toMatchObject({ subject: 'Written before', version: 0, pendingSave: true, remoteVersion: null })
    expect(await db.draft('sending')).toMatchObject({ version: 0, pendingSave: false, outgoingId: 'o1' })
    expect(await db.cursor()).toBe(0)
    await db.close()
    await deleteMailDatabase('upgrade-drafts')
  })
})

describe('IndexedDbMailStore upgrade', () => {
  it('moves a version 1 copy to version 2 and syncs it again from the start', async () => {
    const legacy = await openDB(databaseName('upgrade-user'), 1, {
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
    await legacy.put('meta', 42, 'cursor')
    legacy.close()

    const db = await IndexedDbMailStore.open('upgrade-user')

    expect(await db.cursor()).toBe(0)
    expect(await db.outbox()).toEqual([])
    expect(await db.drafts()).toEqual([])
    await db.close()
    await deleteMailDatabase('upgrade-user')
  })
})

describe('IndexedDbMailStore upgrade to smart inboxes', () => {
  it('keeps the inboxes working and syncs again to bring the classification', async () => {
    const v4 = await openDB(databaseName('upgrade-smart'), 4, {
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
        const outgoing = database.createObjectStore('outgoing', { keyPath: 'id' })
        outgoing.createIndex('byAccount', 'mailAccountId')
        outgoing.createIndex('bySendAt', 'sendAt')
        database.createObjectStore('contacts', { keyPath: 'key' }).createIndex('byLastSeen', 'lastSeen')
        database.createObjectStore('drafts', { keyPath: 'id' }).createIndex('byUpdatedAt', 'updatedAt')
        database.createObjectStore('signatures', { keyPath: 'id' })
      },
    })
    await v4.put('threads', { id: 't1', mailAccountId: 'work', subject: 'Budget', messageCount: 1, lastMessageAt: '2026-10-19T08:00:00Z' })
    await v4.put('conversations', {
      threadId: 't1',
      mailAccountId: 'work',
      subject: 'Budget',
      snippet: '',
      lastMessageAt: 1,
      messageCount: 1,
      unreadCount: 1,
      flagged: false,
      hasAttachments: false,
      senders: [],
      inbox: 1,
      unreadInbox: 1,
    })
    await v4.put('meta', 30, 'cursor')
    v4.close()

    const db = await IndexedDbMailStore.open('upgrade-smart')

    expect(await db.cursor()).toBe(0)
    expect(await db.smartInboxes()).toEqual([])
    expect(await db.inbox(null, 10)).toEqual([expect.objectContaining({ threadId: 't1', smartInboxId: null, verdict: null, inbox: true, active: true })])
    expect((await db.thread('t1'))?.thread).toMatchObject({ smartInboxId: null, verdict: null, classificationReason: null })
    await db.close()
    await deleteMailDatabase('upgrade-smart')
  })
})
