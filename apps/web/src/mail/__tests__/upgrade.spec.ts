import 'fake-indexeddb/auto'
import { describe, expect, it } from 'vitest'
import { openDB } from 'idb'
import { databaseName, deleteMailDatabase, MailDatabase } from '../db'

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
