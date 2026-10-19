import type { ChangePage } from './types'

/** Local copy of the user's data. `apply` must store the page and its cursor in one transaction. */
export interface SyncStore {
  cursor(): Promise<number>
  apply(page: ChangePage): Promise<void>
}

export interface SyncEngineOptions {
  fetchPage(since: number, limit: number): Promise<ChangePage>
  store: SyncStore
  pageSize?: number
  /** Called after each stored page, e.g. to refresh what is on screen. */
  onApplied?(page: ChangePage): void
}

const DEFAULT_PAGE_SIZE = 500

/**
 * Pulls the delta into the local store. Calls never overlap: a request while a pull runs makes that pull go round
 * once more, so every notification is honored without parallel requests or skipped pages.
 */
export class SyncEngine {
  private running: Promise<void> | null = null
  private again = false
  private appliedCursor: number | null = null

  constructor(private readonly options: SyncEngineOptions) {}

  sync(): Promise<void> {
    this.again = true
    if (this.running !== null) {
      return this.running
    }
    this.running = (async () => {
      try {
        while (this.again) {
          this.again = false
          await this.pullAll()
        }
      } finally {
        this.running = null
      }
    })()

    return this.running
  }

  /** A realtime notification: pull only when it is beyond what is stored. */
  notify(cursor: number): Promise<void> {
    if (this.appliedCursor !== null && cursor <= this.appliedCursor) {
      return this.running ?? Promise.resolve()
    }

    return this.sync()
  }

  private async pullAll(): Promise<void> {
    let since = await this.options.store.cursor()
    this.appliedCursor = since
    for (;;) {
      const page = await this.options.fetchPage(since, this.options.pageSize ?? DEFAULT_PAGE_SIZE)
      if (page.cursor < since) {
        throw new Error(`The server returned cursor ${page.cursor}, behind the local cursor ${since}.`)
      }
      if (page.cursor === since) {
        if (page.hasMore) {
          throw new Error(`The server reported more changes but did not advance past cursor ${since}.`)
        }
        return
      }
      await this.options.store.apply(page)
      this.appliedCursor = page.cursor
      this.options.onApplied?.(page)
      since = page.cursor
      if (!page.hasMore) {
        return
      }
    }
  }
}
