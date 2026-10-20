import 'fake-indexeddb/auto'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { CopyClosingError, type ChangePage } from '@tresso/core'
import { databaseName, deleteMailDatabase, IndexedDbMailStore, type ClosedElsewhere } from '@/mail/db'
import { useMailStore } from '../mail'

const USER = 'other-tab-user'

function page(cursor: number): ChangePage {
  return { cursor, hasMore: false, mailAccounts: [], folders: [], threads: [], messages: [], outgoingMessages: [], signatures: [], drafts: [], smartInboxes: [], calendars: [], calendarEvents: [], notifications: [], eventNotes: [], todos: [], mailNotifications: [], handOvers: [], unsubscriptions: [], deleted: [] }
}

/** Another tab opens the copy at a newer version (a newer Tresso), and keeps it open. */
function upgradeInAnotherTab(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(databaseName(USER), 99)
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error)
  })
}

describe('the copy closed by another tab', () => {
  beforeEach(async () => {
    sessionStorage.setItem('auth.tokens', JSON.stringify({ accessToken: 'token', refreshToken: null, expiresAt: Date.now() + 3_600_000 }))
    setActivePinia(createPinia())
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => {
        const url = new URL(String(input))
        if (url.pathname === '/api/v1/sync/changes') {
          return new Response(JSON.stringify(page(Number(url.searchParams.get('since')) + 1)))
        }
        return new Response(JSON.stringify({ detail: 'unavailable' }), { status: 503 })
      }),
    )
    await useMailStore().start(USER)
  })

  afterEach(async () => {
    await useMailStore().stop()
    await deleteMailDatabase(USER)
    vi.unstubAllGlobals()
    sessionStorage.clear()
  })

  it('says to reload when another tab moved to a newer Tresso, and stops using the copy', async () => {
    const mail = useMailStore()
    const other = await upgradeInAnotherTab()

    await vi.waitFor(() => expect(mail.updatedElsewhere).toBe('Tresso was updated in another tab. Reload this tab to keep using it.'))
    expect(mail.ready).toBe(false)
    expect(await mail.thread('t1')).toBeNull()
    other.close()
  })

  it('opens a fresh copy when another tab signed out and removed it', async () => {
    const mail = useMailStore()
    const pulls = mail.pulls

    await deleteMailDatabase(USER)

    await vi.waitFor(() => expect(mail.pulls).toBeGreaterThan(pulls))
    expect(mail.ready).toBe(true)
    expect(mail.updatedElsewhere).toBeNull()
    expect((await indexedDB.databases()).map((database) => database.name)).toContain(databaseName(USER))
  })
})

describe('a copy closed elsewhere', () => {
  it('says why, and every later call says the copy is closing rather than failing on a dead connection', async () => {
    const heard: ClosedElsewhere[] = []
    const store = await IndexedDbMailStore.open('closed-user', (reason) => heard.push(reason))

    await deleteMailDatabase('closed-user')

    expect(heard).toEqual(['deleted'])
    await expect(store.cursor()).rejects.toBeInstanceOf(CopyClosingError)
  })
})
