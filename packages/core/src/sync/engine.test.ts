import { describe, expect, it, vi } from 'vitest'
import { SyncEngine, type SyncStore } from './engine'
import type { ChangePage } from './types'

function page(cursor: number, hasMore = false, ids: string[] = []): ChangePage {
  return {
    cursor,
    hasMore,
    mailAccounts: [],
    folders: [],
    threads: ids.map((id) => ({
      id,
      mailAccountId: 'a',
      subject: id,
      messageCount: 1,
      lastMessageAt: '2026-10-19T08:00:00+00:00',
      smartInboxId: null,
      verdict: null,
      classificationReason: null,
    })),
    messages: [],
    outgoingMessages: [],
    signatures: [],
    drafts: [],
    smartInboxes: [],
    calendars: [],
    calendarEvents: [],
    notifications: [],
    eventNotes: [],
    todos: [],
    mailNotifications: [],
    deleted: [],
  }
}

class MemoryStore implements SyncStore {
  stored = 0
  applied: number[] = []
  async cursor(): Promise<number> {
    return this.stored
  }
  async apply(p: ChangePage): Promise<void> {
    this.applied.push(p.cursor)
    this.stored = p.cursor
  }
}

describe('SyncEngine', () => {
  it('pulls every page from the stored cursor', async () => {
    const store = new MemoryStore()
    const pages = new Map([[0, page(5, true)], [5, page(9)]])
    const fetchPage = vi.fn(async (since: number) => pages.get(since)!)

    await new SyncEngine({ fetchPage, store, pageSize: 2 }).sync()

    expect(fetchPage.mock.calls).toEqual([[0, 2], [5, 2]])
    expect(store.applied).toEqual([5, 9])
  })

  it('stores nothing when nothing changed', async () => {
    const store = new MemoryStore()
    store.stored = 7

    await new SyncEngine({ fetchPage: async () => page(7), store }).sync()

    expect(store.applied).toEqual([])
  })

  it('never runs two pulls at once and honors a request made during a pull', async () => {
    const store = new MemoryStore()
    let release!: () => void
    const gate = new Promise<void>((resolve) => (release = resolve))
    let active = 0
    let maxActive = 0
    const responses = [page(1), page(2)]
    const fetchPage = vi.fn(async () => {
      active++
      maxActive = Math.max(maxActive, active)
      if (fetchPage.mock.calls.length === 1) {
        await gate
      }
      active--
      return responses.shift() ?? page(2)
    })
    const engine = new SyncEngine({ fetchPage, store })

    const first = engine.sync()
    const second = engine.sync()
    release()
    await Promise.all([first, second])

    expect(maxActive).toBe(1)
    expect(store.applied).toEqual([1, 2])
  })

  it('ignores notifications it already has', async () => {
    const store = new MemoryStore()
    const fetchPage = vi.fn(async () => page(3))
    const engine = new SyncEngine({ fetchPage, store })
    await engine.sync()

    await engine.notify(3)
    expect(fetchPage).toHaveBeenCalledTimes(1)

    fetchPage.mockResolvedValueOnce(page(4)).mockResolvedValueOnce(page(4))
    await engine.notify(4)
    expect(store.applied).toEqual([3, 4])
  })

  it('reports each stored page', async () => {
    const onApplied = vi.fn()
    await new SyncEngine({ fetchPage: async () => page(2, false, ['t1']), store: new MemoryStore(), onApplied }).sync()

    expect(onApplied).toHaveBeenCalledWith(expect.objectContaining({ cursor: 2 }))
  })

  it('refuses a server that goes backwards or does not advance', async () => {
    const store = new MemoryStore()
    store.stored = 5

    await expect(new SyncEngine({ fetchPage: async () => page(4), store }).sync()).rejects.toThrow('behind')
    await expect(new SyncEngine({ fetchPage: async () => page(5, true), store }).sync()).rejects.toThrow('did not advance')
  })

  it('can sync again after a failure', async () => {
    const store = new MemoryStore()
    const fetchPage = vi.fn().mockRejectedValueOnce(new Error('offline')).mockResolvedValue(page(1))
    const engine = new SyncEngine({ fetchPage, store })

    await expect(engine.sync()).rejects.toThrow('offline')
    await engine.sync()

    expect(store.applied).toEqual([1])
  })
})
