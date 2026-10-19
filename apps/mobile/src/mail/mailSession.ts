import { RealtimeClient, SyncEngine, type ChangePage, type RealtimeAccess } from '@tresso/core'
import { MailDatabase } from '@/mail/mailDatabase'
import type { Sql } from '@/mail/sql'

const PAGE_SIZE = 500
/** Lists every user's copy that exists on the device, so a copy left behind is found and removed later. */
const INDEX = 'tresso-mail-index.db'

export type SyncState = 'idle' | 'syncing' | 'error'

export interface MailState {
  userId: string | null
  ready: boolean
  /** Goes up whenever the local copy changed, so screens read it again. */
  version: number
  syncState: SyncState
  syncError: string | null
  /** The realtime connection is up, so changes arrive as they happen. */
  live: boolean
}

export interface MailSessionDeps {
  openSql(name: string): Promise<Sql & { close(): Promise<void> }>
  deleteSql(name: string): Promise<void>
  fetchPage(since: number, limit: number): Promise<ChangePage>
  fetchAccess(): Promise<RealtimeAccess>
  fetch?: typeof fetch
  onState(state: MailState): void
}

interface Open {
  userId: string
  sql: Sql & { close(): Promise<void> }
  database: MailDatabase
  engine: SyncEngine
  realtime: RealtimeClient
}

export function databaseName(userId: string): string {
  return `mail-${userId}.db`
}

/**
 * The lifecycle of the mail copies on the device: which user's copy is open, kept current, and closed again; the
 * copies of everyone else are removed. Switches run one after another and every step is awaited, so a copy is only
 * deleted once it is closed and a sign-out during opening leaves nothing behind.
 */
export class MailSession {
  private state: MailState = { userId: null, ready: false, version: 0, syncState: 'idle', syncError: null, live: false }
  private queue: Promise<void> = Promise.resolve()
  /** Goes up with every switch asked for: a switch that a newer one replaced stops where it is. */
  private wanted = 0
  private open: Open | null = null
  private foreground = true

  constructor(private readonly deps: MailSessionDeps) {}

  get database(): MailDatabase | null {
    return this.open?.database ?? null
  }

  /** Opens this user's copy (null: nobody is signed in) and removes everyone else's. */
  switchTo(userId: string | null): Promise<void> {
    const switchId = ++this.wanted
    this.queue = this.queue.then(() => this.apply(userId, switchId)).catch((error: unknown) => this.update({ syncState: 'error', syncError: message(error) }))
    return this.queue
  }

  /** The app came to the front (listen and catch up) or went to the background (the connection rests). */
  setForeground(active: boolean): void {
    this.foreground = active
    const open = this.open
    if (open === null) {
      return
    }
    if (active) {
      open.realtime.start()
      void this.pull(open, () => open.engine.sync())
    } else {
      this.update({ live: false })
      void open.realtime.stop()
    }
  }

  syncNow(): Promise<void> {
    const open = this.open
    return open === null ? Promise.resolve() : this.pull(open, () => open.engine.sync())
  }

  private async apply(userId: string | null, switchId: number): Promise<void> {
    if (this.open?.userId === userId && userId !== null) {
      return
    }
    await this.close()
    await this.removeCopiesExcept(userId === null ? null : databaseName(userId))
    if (userId === null || switchId !== this.wanted) {
      return
    }

    const name = databaseName(userId)
    const index = await this.deps.openSql(INDEX)
    try {
      await index.exec('CREATE TABLE IF NOT EXISTS copies (name TEXT PRIMARY KEY NOT NULL)')
      await index.run('INSERT OR IGNORE INTO copies (name) VALUES (?)', [name])
    } finally {
      await index.close()
    }
    const sql = await this.deps.openSql(name)
    let database: MailDatabase
    try {
      database = await MailDatabase.open(sql)
    } catch (error) {
      await sql.close()
      throw error
    }
    if (switchId !== this.wanted) {
      await sql.close()
      return
    }

    const engine = new SyncEngine({
      store: database,
      pageSize: PAGE_SIZE,
      fetchPage: (since, limit) => this.deps.fetchPage(since, limit),
      onApplied: () => {
        if (this.open?.database === database) {
          this.update({ version: this.state.version + 1 })
        }
      },
    })
    // The callbacks only act for the copy that is open when they come.
    let open: Open | null = null
    const realtime = new RealtimeClient({
      fetchAccess: () => this.deps.fetchAccess(),
      fetch: this.deps.fetch,
      onCursor: (cursor) => {
        if (open !== null) {
          void this.pull(open, () => engine.notify(cursor))
        }
      },
      onConnected: () => {
        if (open !== null && this.open === open) {
          this.update({ live: true })
          void this.pull(open, () => engine.sync())
        }
      },
      onError: () => {
        if (open !== null && this.open === open) {
          this.update({ live: false })
        }
      },
    })
    open = { userId, sql, database, engine, realtime }
    const opened = open
    this.open = opened
    this.update({ userId, ready: true, version: this.state.version + 1, syncError: null })
    await this.pull(opened, () => engine.sync())
    if (this.open === opened && this.foreground) {
      opened.realtime.start()
    }
  }

  private async close(): Promise<void> {
    const open = this.open
    this.open = null
    if (open === null) {
      return
    }
    this.update({ userId: null, ready: false, live: false, syncState: 'idle' })
    await open.realtime.stop()
    await open.sql.close()
  }

  /** Deletes every listed copy except this one; one that cannot go now stays listed and is tried again next time. */
  private async removeCopiesExcept(keep: string | null): Promise<void> {
    const index = await this.deps.openSql(INDEX)
    try {
      await index.exec('CREATE TABLE IF NOT EXISTS copies (name TEXT PRIMARY KEY NOT NULL)')
      for (const { name } of await index.all<{ name: string }>('SELECT name FROM copies')) {
        if (name === keep) {
          continue
        }
        try {
          await this.deps.deleteSql(name)
          await index.run('DELETE FROM copies WHERE name = ?', [name])
        } catch (error) {
          this.update({ syncError: `A copy of mail on this device could not be removed yet: ${message(error)}` })
        }
      }
    } finally {
      await index.close()
    }
  }

  private async pull(open: Open, run: () => Promise<void>): Promise<void> {
    if (this.open !== open) {
      return
    }
    this.update({ syncState: 'syncing' })
    try {
      await run()
      if (this.open === open) {
        this.update({ syncState: 'idle', syncError: null })
      }
    } catch (error) {
      if (this.open === open) {
        this.update({ syncState: 'error', syncError: message(error) })
      }
    }
  }

  private update(change: Partial<MailState>): void {
    this.state = { ...this.state, ...change }
    this.deps.onState(this.state)
  }
}

function message(error: unknown): string {
  return error instanceof Error ? error.message : String(error)
}
