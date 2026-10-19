import 'fake-indexeddb/auto'
import { describe, expect, it } from 'vitest'
import { openDB } from 'idb'
import { databaseName, deleteMailDatabase, MailDatabase } from '../db'

describe('MailDatabase upgrade to synced drafts', () => {
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

    const db = await MailDatabase.open('upgrade-drafts')

    expect(await db.draft('written')).toMatchObject({ subject: 'Written before', version: 0, pendingSave: true, remoteVersion: null })
    expect(await db.draft('sending')).toMatchObject({ version: 0, pendingSave: false, outgoingId: 'o1' })
    expect(await db.cursor()).toBe(0)
    db.close()
    await deleteMailDatabase('upgrade-drafts')
  })
})

describe('MailDatabase upgrade', () => {
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

    const db = await MailDatabase.open('upgrade-user')

    expect(await db.cursor()).toBe(0)
    expect(await db.outbox()).toEqual([])
    expect(await db.drafts()).toEqual([])
    db.close()
    await deleteMailDatabase('upgrade-user')
  })
})
