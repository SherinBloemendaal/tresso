import {
  appliedFor,
  MAX_MESSAGES_PER_ACTION,
  PartialActionError,
  RealtimeClient,
  SyncEngine,
  type AppliedAction,
  type ChangePage,
  type MessageAction,
  type MessageActionRequest,
  type RealtimeAccess,
  type Sql,
  CopyClosingError,
  DraftSync,
  SqliteMailStore,
  unsubscribeOffer,
  type DraftApi,
  type SyncedUnsubscription,
  type UnsubscribeOffer,
  type UnsubscribeRequest,
} from '@tresso/core'

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
  /**
   * Pulls of the delta that completed: a screen opened from a notification waits for one after it opened before it
   * says a conversation is not there (it may not have reached the copy yet).
   */
  pulls: number
}

export interface MailSessionDeps {
  openSql(name: string): Promise<Sql & { close(): Promise<void> }>
  deleteSql(name: string): Promise<void>
  fetchPage(since: number, limit: number): Promise<ChangePage>
  fetchAccess(): Promise<RealtimeAccess>
  /** POST /api/v1/message-actions */
  postAction(request: MessageActionRequest): Promise<void>
  /** POST /api/v1/unsubscriptions */
  postUnsubscribe(request: UnsubscribeRequest): Promise<SyncedUnsubscription>
  /** /api/v1/drafts */
  drafts: DraftApi
  fetch?: typeof fetch
  onState(state: MailState): void
  /** Whether the app is in front when the session starts (it can be launched in the background). */
  foreground?: boolean
}

interface Open {
  userId: string
  sql: Sql & { close(): Promise<void> }
  database: SqliteMailStore
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
  private state: MailState = { userId: null, ready: false, version: 0, syncState: 'idle', syncError: null, live: false, pulls: 0 }
  private queue: Promise<void> = Promise.resolve()
  /** Goes up with every switch asked for: a switch that a newer one replaced stops where it is. */
  private wanted = 0
  private open: Open | null = null
  private foreground: boolean
  /** Brings drafts written on the phone to the server, for whichever copy is open. */
  readonly drafts: DraftSync

  constructor(private readonly deps: MailSessionDeps) {
    this.foreground = deps.foreground ?? true
    this.drafts = new DraftSync(() => this.database, deps.drafts, () => this.changed())
  }

  get database(): SqliteMailStore | null {
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

  /**
   * Reads, flags, archives, trashes or moves messages: the change shows at once and is undone for the messages the
   * server did not take. Large selections go in batches; when a later batch fails, a PartialActionError carries what
   * went through. Returns the messages before and after, for an undo.
   */
  async act(messageIds: string[], action: MessageAction, target: { folderId?: string; fromFolderId?: string } = {}): Promise<AppliedAction> {
    if (messageIds.length === 0) {
      return { before: [], after: [] }
    }
    const database = this.database
    if (database === null) {
      throw new Error('Your mail is not open on this phone yet.')
    }
    const applied = await database.applyAction(messageIds, action, target.folderId, target.fromFolderId)
    this.changed()
    let accepted = 0
    try {
      for (let start = 0; start < messageIds.length; start += MAX_MESSAGES_PER_ACTION) {
        const request: MessageActionRequest = { messageIds: messageIds.slice(start, start + MAX_MESSAGES_PER_ACTION), action, ...target }
        await this.deps.postAction(request)
        accepted = start + request.messageIds.length
      }
    } catch (error) {
      // Earlier batches went through; only the rest goes back. A copy closing meanwhile is about to be removed, so
      // there is nothing to put back; either way the user hears why the action failed, not why the undo did.
      let resyncing = false
      try {
        await database.revertAction(appliedFor(applied, new Set(messageIds.slice(accepted))))
      } catch (revertError) {
        if (!(revertError instanceof CopyClosingError)) {
          // The phone still shows a change the server never made, and no page of the delta would mention those
          // messages: the copy syncs from the start, which puts them right. If even that cannot be asked for, the
          // user hears only why the action failed.
          resyncing = await database.resyncFromStart().then(
            () => true,
            () => false,
          )
        }
      }
      const failure = resyncing ? new Error(`${message(error)} This phone shows the change until it has synced your mail again.`) : error
      this.changed()
      void this.syncNow()
      if (accepted > 0) {
        throw new PartialActionError(failure, appliedFor(applied, new Set(messageIds.slice(0, accepted))), messageIds.length - accepted)
      }
      throw failure
    }
    void this.syncNow()
    return applied
  }

  /**
   * What a conversation offers for one-click unsubscribe (see `unsubscribeOffer`); nothing for spam, which is never
   * answered.
   */
  async unsubscribeOffer(threadId: string): Promise<UnsubscribeOffer | null> {
    const database = this.database
    const found = await database?.thread(threadId)
    if (!database || !found) {
      return null
    }
    const spam = (await database.smartInboxes()).find((smartInbox) => smartInbox.builtin === 'spam')
    if (spam !== undefined && found.thread.smartInboxId === spam.id) {
      return null
    }
    return unsubscribeOffer(found.messages, await database.unsubscriptions())
  }

  /**
   * Unsubscribes the account that received a message from its sender. The request shows at once (the button goes);
   * its outcome arrives with the delta.
   */
  async unsubscribe(messageId: string): Promise<SyncedUnsubscription> {
    const database = this.database
    if (database === null) {
      throw new Error('Your mail is not open on this phone yet.')
    }
    const unsubscription = await this.deps.postUnsubscribe({ messageId })
    await database.noteUnsubscription(unsubscription)
    this.changed()
    void this.syncNow()
    return unsubscription
  }

  /** The copy changed outside a pull (an action shown before the server confirms it): screens read it again. */
  changed(): void {
    this.update({ version: this.state.version + 1 })
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
    let database: SqliteMailStore
    try {
      database = await SqliteMailStore.open(sql)
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
    // Not awaited: the copy on the device shows right away, and a switch (a sign-out) does not wait for the pull.
    void this.pull(opened, () => engine.sync())
    // Drafts written while offline (or before the app was closed) go up now.
    void this.drafts.uploadPending()
    if (this.foreground) {
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
    // Waits for a page being stored, not for one on its way over the network: that one is refused when it arrives.
    await open.database.close()
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
        this.update({ syncState: 'idle', syncError: null, pulls: this.state.pulls + 1 })
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
