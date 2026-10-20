/**
 * A triage round as the web client and the phone run it, on top of the pure state in `./triage`. The host (a Vue
 * composable, a React hook) supplies the mail operations and draws `state`, `conversation` and `pending`.
 *
 * Decisions and undos run one after another in the order the user asked for them; none is ever dropped, and one that
 * fails never stops the next. The screen moves on at once: a key pressed while an earlier decision still runs applies
 * to the conversation then on screen. Hand over first runs its hook (a dialog, a forward) outside that order, so the
 * round goes on while it is open; the conversation is only placed once the hook says it was handed over.
 */

import { undoPlan, type AppliedAction, type UndoMove } from './actions'
import type { ConversationSummary } from './sync/conversation'
import type { SyncedFolder, SyncedMessage, SyncedSmartInbox, SyncedThread } from './sync/types'
import type { MadeTodo, MakeTodoRequest } from './todos'
import {
  currentTriage,
  decideTriage,
  followTriage,
  needsTriage,
  skipTriage,
  startTriage,
  triagePlacement,
  undoTriage,
  type TriageDecision,
  type TriageEntry,
  type TriageState,
} from './triage'

/** Conversations a round takes at most; the rest waits for the next round. */
export const TRIAGE_ROUND = 100

/** How many of the newest inbox conversations a follow looks at for mail that arrived during the round. */
const ARRIVALS = 50

export interface TriageConversation {
  thread: SyncedThread
  messages: SyncedMessage[]
}

/** Takes back exactly what a hook did. */
export interface TriageUndo {
  undo(): Promise<void>
}

/** Where other features plug in; each returns how to take back what it did. */
export interface TriageHooks {
  /** After a conversation was marked as something to do: makes its todo. null when it made none. */
  afterDo?: (conversation: TriageConversation) => Promise<TriageUndo | null>
  /**
   * Hands the conversation over (a dialog, or a forward): resolves once that is done, or with null when the user
   * cancelled. It may stay open as long as the user likes; the round goes on meanwhile.
   */
  handOver: (conversation: TriageConversation) => Promise<TriageUndo | null>
}

/**
 * The `afterDo` hook of todos: do makes the conversation's todo from its newest email someone else sent (the newest
 * when there is none), unless the conversation has an open todo already. Undo removes only a todo it made.
 *
 * @param ownAddresses the user's own addresses, lower case
 */
export function todoOnDo(todos: {
  makeFromMessage(messageId: string, request: MakeTodoRequest): Promise<MadeTodo>
  remove(todoId: string): Promise<void>
  ownAddresses(): Promise<string[]>
}): NonNullable<TriageHooks['afterDo']> {
  return async (conversation) => {
    const own = new Set((await todos.ownAddresses()).map((address) => address.toLowerCase()))
    const incoming = conversation.messages.filter((message) => !own.has(message.from.address.toLowerCase()))
    const source = incoming[incoming.length - 1] ?? conversation.messages[conversation.messages.length - 1]
    if (source === undefined) {
      return null
    }
    const todo = await todos.makeFromMessage(source.id, { perConversation: true })
    return todo.made ? { undo: () => todos.remove(todo.id) } : null
  }
}

export interface TriageNotice {
  text: string
  tone: 'info' | 'error'
  action?: { label: string; accessibilityLabel: string; run: () => void }
}

export interface TriageDeps {
  /** Up to `limit` conversations that wait for triage (see `needsTriage`), newest first. */
  waiting(limit: number): Promise<string[]>
  /** The newest `limit` conversations of the inboxes, whatever their state. */
  newest(limit: number): Promise<ConversationSummary[]>
  /** The stored summaries of these conversations. */
  summaries(threadIds: string[]): Promise<ConversationSummary[]>
  thread(threadId: string): Promise<TriageConversation | null>
  smartInboxes(): Promise<Pick<SyncedSmartInbox, 'id' | 'builtin'>[]>
  folders(): Promise<SyncedFolder[]>
  /** Places only this conversation (nothing remembered for the sender); returns the placement's id. */
  place(threadId: string, smartInboxId: string, verdict: 'do' | 'delegate'): Promise<string>
  /** Takes a placement back; 'moved' when the conversation was moved again since, and that choice stays. */
  release(threadId: string, placementId: string): Promise<'released' | 'moved'>
  /** A message action; null when it did not happen (the host told the user why). */
  act(messageIds: string[], action: 'archive' | 'markRead' | 'markUnread'): Promise<AppliedAction | null>
  /** Puts back what an undo plan moves (the host tells the user what failed); returns the moves that failed. */
  undoMoves(moves: UndoMove[]): Promise<UndoMove[]>
  notify(notice: TriageNotice): void
  /** What a failed request means for the user. */
  explain(error: unknown): string
  hooks: TriageHooks
  /** Something the screen shows changed. */
  changed(): void
}

/** How to take a decision back exactly: what it changed, and only that. */
interface Taken {
  threadId: string
  decision: TriageDecision
  /** Messages the decision marked read. */
  read: string[]
  /** For know: the archive's undo plan (what is left of it after an undo that went partly through). */
  moves: UndoMove[]
  /** For do and hand over: the placement's id, so undo never takes back a later choice. */
  placementId: string | null
  /** What a hook did (a todo, a hand-over). */
  hook: TriageUndo | null
}

const NOTICE: Record<TriageDecision, string> = {
  do: 'Marked as to do.',
  delegate: 'Handed over.',
  know: 'Noted and archived.',
}

const UNDO_LABEL: Record<TriageDecision, string> = {
  do: 'Undo to do',
  delegate: 'Undo hand over',
  know: 'Undo noted',
}

export class TriageSession {
  state: TriageState | null = null
  /** The conversation on screen; null when the round is over (or not started). */
  conversation: TriageConversation | null = null
  /** Decisions and undos waiting or running. */
  pending = 0
  /** More conversations wait than this round took. */
  more = false

  private seq = 0
  private queue: Promise<void> = Promise.resolve()
  private readonly taken = new Map<number, Taken>()
  /** Settles once decision id is done (taken, or reverted), so its undo waits for it without holding up the rest. */
  private readonly settled = new Map<number, Promise<void>>()
  /** Decisions an undo was asked for; the next undo goes for the one before. */
  private readonly undoing = new Set<number>()
  /** Decisions that can no longer be undone, and why; undo passes over them. */
  private readonly final = new Map<number, string>()
  /** Mail newer than this joins the round as it arrives (epoch ms). */
  private since = 0
  private following: Promise<void> | null = null
  private followAgain = false

  constructor(private readonly deps: TriageDeps) {}

  /** Starts a round over the conversations waiting now. */
  async begin(): Promise<void> {
    const ids = await this.deps.waiting(TRIAGE_ROUND + 1)
    this.more = ids.length > TRIAGE_ROUND
    const first = ids[0] === undefined ? [] : await this.deps.summaries([ids[0]])
    this.since = first[0]?.lastMessageAt ?? Date.now()
    this.state = startTriage(ids.slice(0, TRIAGE_ROUND))
    this.taken.clear()
    this.settled.clear()
    this.undoing.clear()
    this.final.clear()
    this.conversation = null
    this.deps.changed()
    await this.show()
  }

  /** Whether there is a decision left that undo can go for. */
  get canUndo(): boolean {
    return this.undoTarget() !== null
  }

  /**
   * Follows changes of the copy (call it on every change; overlapping calls run once more after the current one):
   * conversations that stopped waiting are passed over, mail that arrived joins the round, one that waits again
   * comes back. Reads only the round's conversations and the newest of the inbox.
   */
  follow(): Promise<void> {
    if (this.following !== null) {
      this.followAgain = true
      return this.following
    }
    this.following = (async () => {
      do {
        this.followAgain = false
        await this.followOnce()
      } while (this.followAgain)
    })().finally(() => {
      this.following = null
    })
    return this.following
  }

  /**
   * Decides the conversation on screen, as it is on screen: only the messages shown are archived and read, so a reply
   * that arrives meanwhile waits for the next round. Ignored while the next email still loads. The screen moves on at
   * once.
   */
  decide(decision: TriageDecision): void {
    const threadId = this.state === null ? null : currentTriage(this.state)
    const shown = this.conversation
    if (this.state === null || threadId === null || shown === null || shown.thread.id !== threadId) {
      return
    }
    const id = ++this.seq
    this.state = decideTriage(this.state, decision, id)
    this.deps.changed()
    void this.show()
    const done = decision === 'delegate' ? this.handOver(id, shown) : this.enqueue(() => this.perform(id, shown, decision, null))
    this.settled.set(id, done)
  }

  /** Leaves the conversation on screen as it is and goes on. */
  skip(): void {
    if (this.state === null) {
      return
    }
    this.state = skipTriage(this.state)
    this.deps.changed()
    void this.show()
  }

  /**
   * Takes a decision back (the last one not being undone yet when none is given), once that decision is done: the
   * conversation is on screen again.
   */
  undo(id?: number): void {
    const entry = id === undefined ? this.undoTarget() : (this.state?.decided.find((candidate) => candidate.id === id) ?? null)
    if (entry === null || this.undoing.has(entry.id)) {
      return
    }
    this.undoing.add(entry.id)
    this.deps.changed()
    void (this.settled.get(entry.id) ?? Promise.resolve()).then(() => this.enqueue(() => this.takeBack(entry)))
  }

  /** Settles once every decision and undo asked for so far is done. */
  async idle(): Promise<void> {
    let seen: Promise<void> | null = null
    while (seen !== this.queue) {
      seen = this.queue
      await Promise.all([seen, ...this.settled.values()])
    }
  }

  private undoTarget(): TriageEntry | null {
    const decided = this.state?.decided ?? []
    for (let index = decided.length - 1; index >= 0; index -= 1) {
      if (!this.undoing.has(decided[index]!.id) && !this.final.has(decided[index]!.id)) {
        return decided[index]!
      }
    }
    return null
  }

  /** Runs tasks one after another; a failing one is reported and the next still runs. */
  private enqueue(task: () => Promise<void>): Promise<void> {
    this.pending += 1
    this.deps.changed()
    const run = this.queue
      .then(task)
      .catch((error: unknown) => this.deps.notify({ text: this.deps.explain(error), tone: 'error' }))
      .finally(() => {
        this.pending -= 1
        this.deps.changed()
      })
    this.queue = run
    return run
  }

  /** Loads the conversation on screen; one gone meanwhile is passed over. */
  private async show(): Promise<void> {
    for (;;) {
      const threadId = this.state === null ? null : currentTriage(this.state)
      if (threadId === null) {
        if (this.conversation !== null) {
          this.conversation = null
          this.deps.changed()
        }
        return
      }
      if (this.conversation?.thread.id === threadId) {
        return
      }
      const loaded = await this.deps.thread(threadId)
      if (this.state === null || currentTriage(this.state) !== threadId) {
        continue
      }
      if (loaded !== null && loaded.messages.length > 0) {
        this.conversation = loaded
        this.deps.changed()
        return
      }
      this.state = skipTriage(this.state)
    }
  }

  private async followOnce(): Promise<void> {
    const state = this.state
    if (state === null) {
      return
    }
    const decided = new Set(state.decided.map((entry) => entry.threadId))
    const members = state.queue.filter((threadId) => !decided.has(threadId))
    const [summaries, newest] = await Promise.all([this.deps.summaries(members), this.deps.newest(ARRIVALS)])
    const waiting = new Set(summaries.filter(needsTriage).map((conversation) => conversation.threadId))
    const arrivals = newest.filter((conversation) => needsTriage(conversation) && conversation.lastMessageAt > this.since).map((conversation) => conversation.threadId)
    if (this.state === null) {
      return
    }
    // Decisions taken while this ran are kept: followTriage never passes over a decided conversation.
    const next = followTriage(this.state, waiting, arrivals, new Set(members))
    if (next !== this.state) {
      this.state = next
      this.deps.changed()
    }
    await this.show()
  }

  /** Hand over: the hook first, outside the order of decisions; placed only once it says it was handed over. */
  private async handOver(id: number, shown: TriageConversation): Promise<void> {
    let handle: TriageUndo | null
    try {
      handle = await this.deps.hooks.handOver(shown)
    } catch (error) {
      this.deps.notify({ text: this.deps.explain(error), tone: 'error' })
      handle = null
    }
    if (handle === null) {
      // Cancelled (or it failed): nothing was handed over, and the conversation waits again.
      this.revert(id)
      return
    }
    await this.enqueue(() => this.perform(id, shown, 'delegate', handle))
  }

  /** Runs a decision and records how to take it back; reverts it when it did not happen (the user is told why). */
  private async perform(id: number, decided: TriageConversation, decision: TriageDecision, handOver: TriageUndo | null): Promise<void> {
    const threadId = decided.thread.id
    // The messages shown when the user decided, as far as they are still there now.
    const now = await this.deps.thread(threadId)
    const still = new Map((now?.messages ?? []).map((message) => [message.id, message]))
    const messages = decided.messages.filter((message) => still.has(message.id)).map((message) => still.get(message.id)!)
    if (now === null || messages.length === 0) {
      await this.withdraw(handOver)
      this.revert(id)
      return
    }
    const shown: TriageConversation = { thread: now.thread, messages }
    const unread = messages.filter((message) => !message.flags.seen).map((message) => message.id)
    let moves: UndoMove[] = []
    let unrestorable = 0
    let placementId: string | null = null
    if (decision === 'know') {
      const applied = await this.deps.act(
        shown.messages.map((message) => message.id),
        'archive',
      )
      if (applied === null) {
        this.revert(id)
        return
      }
      const plan = undoPlan(applied, 'archive', await this.deps.folders())
      moves = plan.moves
      unrestorable = plan.unrestorable
    } else {
      const placement = triagePlacement(decision, shown.thread.smartInboxId ?? null, await this.deps.smartInboxes())
      try {
        if (placement === null) {
          throw new Error('Your smart inboxes have not arrived yet. Try again in a moment.')
        }
        placementId = await this.deps.place(threadId, placement.smartInboxId, placement.verdict)
      } catch (error) {
        this.deps.notify({ text: this.deps.explain(error), tone: 'error' })
        await this.withdraw(handOver)
        this.revert(id)
        return
      }
    }
    // Reading is part of deciding, but not worth undoing the decision for when it fails (act says so).
    const read = unread.length > 0 && (await this.deps.act(unread, 'markRead')) !== null ? unread : []
    let hook = handOver
    if (decision === 'do' && this.deps.hooks.afterDo !== undefined) {
      try {
        hook = await this.deps.hooks.afterDo(shown)
      } catch (error) {
        this.deps.notify({ text: this.deps.explain(error), tone: 'error' })
      }
    }

    // Messages that were in several folders cannot be put back by an archive's undo (see undoPlan).
    const undoable = decision !== 'know' || moves.length > 0
    if (undoable) {
      this.taken.set(id, { threadId, decision, read, moves, placementId, hook })
    } else {
      this.final.set(id, 'This one cannot be undone: its messages were in several folders.')
    }
    const caveat = unrestorable === 0 ? '' : !undoable ? ' Undo cannot put it back.' : ` Undo cannot put ${unrestorable === 1 ? 'one message' : `${unrestorable} messages`} back.`
    const text = `${NOTICE[decision]}${caveat}`
    this.deps.notify(undoable ? { text, tone: 'info', action: { label: 'Undo', accessibilityLabel: UNDO_LABEL[decision], run: () => this.undo(id) } } : { text, tone: 'info' })
  }

  private async takeBack(entry: TriageEntry): Promise<void> {
    try {
      let taken = this.taken.get(entry.id)
      if (taken === undefined) {
        // Never happened (reverted meanwhile), or it can no longer be undone.
        const reason = this.final.get(entry.id)
        if (reason !== undefined) {
          this.deps.notify({ text: reason, tone: 'info' })
        }
        return
      }
      const placementId = taken.placementId
      if (placementId !== null) {
        // What the decision did besides placing goes first, so the server's rules see the mail as it was before it:
        // unread, without the todo do made (classification gives new mail its automatic todo only then).
        const read = taken.read
        if (read.length > 0 && (await this.deps.act(read, 'markUnread')) !== null) {
          this.taken.set(entry.id, (taken = { ...taken, read: [] }))
        }
        if (taken.hook !== null) {
          try {
            await taken.hook.undo()
          } catch (error) {
            // The todo or the hand-over still stands: so does the decision, read as it was, to try again.
            if (taken.read.length === 0 && read.length > 0 && (await this.deps.act(read, 'markRead')) !== null) {
              this.taken.set(entry.id, (taken = { ...taken, read }))
            }
            this.deps.notify({ text: `This could not be undone: ${this.deps.explain(error)}`, tone: 'error' })
            return
          }
          this.taken.set(entry.id, (taken = { ...taken, hook: null }))
        }
        let outcome: 'released' | 'moved'
        try {
          outcome = await this.deps.release(taken.threadId, placementId)
        } catch (error) {
          // Tried again from here on the next undo.
          this.deps.notify({ text: `This could not be undone: ${this.deps.explain(error)}`, tone: 'error' })
          return
        }
        if (outcome === 'moved') {
          this.taken.delete(entry.id)
          this.final.set(entry.id, 'This conversation was moved again since, so it stays where it was moved.')
          this.deps.notify({ text: 'Undone, but this conversation was moved again since, so it stays where it was moved.', tone: 'info' })
          return
        }
      } else {
        const left = await this.deps.undoMoves(taken.moves)
        if (left.length > 0) {
          // Only what failed is tried again next time; the conversation stays decided until all of it is back.
          this.taken.set(entry.id, { ...taken, moves: left })
          return
        }
      }
      if (taken.read.length > 0) {
        await this.deps.act(taken.read, 'markUnread')
      }
      this.taken.delete(entry.id)
      this.revert(entry.id)
      await this.follow()
      if (this.state !== null && currentTriage(this.state) !== taken.threadId) {
        // The server took it back, but this copy has not synced that yet: once it has, the conversation joins the
        // end of this round (see followTriage).
        this.deps.notify({ text: 'Undone. The conversation comes back in this round once your mail has synced.', tone: 'info' })
      }
    } finally {
      this.undoing.delete(entry.id)
      this.deps.changed()
    }
  }

  /** Takes back what a hook did; a failure is reported, the rest of the undo still happens. */
  private async withdraw(hook: TriageUndo | null): Promise<void> {
    try {
      await hook?.undo()
    } catch (error) {
      this.deps.notify({ text: this.deps.explain(error), tone: 'error' })
    }
  }

  /** The decision did not happen, or was undone: its conversation is on screen again. */
  private revert(id: number): void {
    if (this.state === null) {
      return
    }
    this.state = undoTriage(this.state, id)
    this.deps.changed()
    void this.show()
  }
}
