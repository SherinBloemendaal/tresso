import 'fake-indexeddb/auto'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { SqliteMailStore, type Sql } from '@tresso/core'
import { draft } from '@tresso/core/testing'
import { memorySql } from '@tresso/core/testing/sql'
import { databaseName, deleteMailDatabase, IndexedDbMailStore } from '../db'

const desktop = vi.hoisted(() => ({ on: true, files: new Map<string, Sql>(), deleted: [] as string[] }))

vi.mock('@/platform/desktop', () => ({
  get isDesktop() {
    return desktop.on
  },
}))
vi.mock('@/platform/desktopSql', () => ({
  openDesktopSql: async (name: string) => {
    if (!desktop.files.has(name)) {
      desktop.files.set(name, await memorySql())
    }
    return desktop.files.get(name)!
  },
  deleteDesktopSql: async (name: string) => {
    desktop.deleted.push(name)
    desktop.files.delete(name)
  },
}))

const { deleteMailStore, openMailStore } = await import('../store')

async function indexedDbExists(userId: string): Promise<boolean> {
  return (await indexedDB.databases()).some((database) => database.name === databaseName(userId))
}

describe('openMailStore', () => {
  afterEach(async () => {
    desktop.on = true
    desktop.files.clear()
    desktop.deleted = []
    await deleteMailDatabase('kim')
  })

  it('keeps mail in IndexedDB in the browser', async () => {
    desktop.on = false

    const store = await openMailStore('kim')

    expect(store).toBeInstanceOf(IndexedDbMailStore)
    await store.close()
  })

  it('keeps mail in SQLite on the desktop and carries over drafts only the old copy had', async () => {
    const old = await IndexedDbMailStore.open('kim')
    await old.saveDraft(draft('unsent', { pendingSave: true, version: 2 }))
    await old.saveDraft(draft('sending', { outgoingId: 'o1' }))
    await old.saveDraft(draft('synced', { version: 3 }))
    await old.close()

    const store = await openMailStore('kim')

    expect(store).toBeInstanceOf(SqliteMailStore)
    expect((await store.drafts()).map((d) => d.id).sort()).toEqual(['sending', 'unsent'])
    expect(await store.draft('unsent')).toMatchObject({ pendingSave: true, version: 2 })
    expect(await indexedDbExists('kim')).toBe(false)
    await store.close()
  })

  it('leaves a draft alone that the SQLite copy has already', async () => {
    const old = await IndexedDbMailStore.open('kim')
    await old.saveDraft(draft('d1', { pendingSave: true, subject: 'Old' }))
    await old.close()
    const sql = await memorySql()
    desktop.files.set(databaseName('kim'), sql)
    await (await SqliteMailStore.open(sql)).saveDraft(draft('d1', { pendingSave: true, subject: 'Newer' }))

    const store = await openMailStore('kim')

    expect(await store.draft('d1')).toMatchObject({ subject: 'Newer' })
    await store.close()
  })

  it('removes both copies on sign-out', async () => {
    const old = await IndexedDbMailStore.open('kim')
    await old.close()

    await deleteMailStore('kim')

    expect(desktop.deleted).toEqual([databaseName('kim')])
    expect(await indexedDbExists('kim')).toBe(false)
  })
})
