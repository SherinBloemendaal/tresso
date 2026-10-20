import { TriageSession, todoOnDo, type AppliedAction, type HandedOver, type MadeTodo, type MakeTodoRequest, type SyncedMailAccount, type ConversationSummary, type SyncedFolder, type SyncedMessage, type SyncedSmartInbox, type SyncedThread, type ThreadClassification, type TriageConversation, type TriageHooks, type TriageNotice, type TriageUndo, type UndoMove, type Verdict } from '@tresso/core'

/** What the phone's triage reads and changes; read again on every call, so it can follow re-renders. */
export interface TriageHost {
  mail: {
    triageWaiting(limit: number): Promise<string[]>
    newestInInbox(limit: number): Promise<ConversationSummary[]>
    summaries(threadIds: string[]): Promise<ConversationSummary[]>
    thread(threadId: string): Promise<{ thread: SyncedThread; messages: SyncedMessage[] } | null>
    smartInboxes(): Promise<SyncedSmartInbox[]>
    folders(): Promise<SyncedFolder[]>
    classifyThread(threadId: string, smartInboxId: string, verdict: Verdict, remember: boolean): Promise<ThreadClassification>
    releaseThread(threadId: string, placementId: string): Promise<'released' | 'moved'>
    makeTodo(messageId: string, request?: MakeTodoRequest): Promise<MadeTodo>
    removeTodo(id: string): Promise<void>
    mailAccounts(): Promise<SyncedMailAccount[]>
  }
  handOvers: {
    handOverAndWait(threadId: string): Promise<HandedOver | null>
    takeBack(handed: HandedOver): Promise<unknown>
  }
  notify(notice: TriageNotice): void
  run(messageIds: string[], action: 'archive' | 'markRead' | 'markUnread'): Promise<AppliedAction | null>
  undoMoves(moves: UndoMove[]): Promise<UndoMove[]>
  hooks: Partial<TriageHooks>
}

function errorText(error: unknown): string {
  return error instanceof Error ? error.message : String(error)
}

/**
 * The phone's triage round (`TriageSession` in `@tresso/core`, as on the web) on the phone's mail, hand-overs and
 * notices. Do makes the conversation's todo unless it has an open one (see `todoOnDo`); without a hand-over hook, hand
 * over opens the hand-over screen, which never holds up the round.
 */
export function createTriageSession(host: () => TriageHost, changed: () => void): TriageSession {
  /** Hand over through the hand-over screen; undo takes the forward back while it has not left, the hand-over always. */
  async function handOver(conversation: TriageConversation): Promise<TriageUndo | null> {
    const handed = await host().handOvers.handOverAndWait(conversation.thread.id)
    if (handed === null) {
      return null
    }
    return {
      undo: async () => {
        await host().handOvers.takeBack(handed)
      },
    }
  }

  const makeTodo = todoOnDo({
    makeFromMessage: (messageId, request) => host().mail.makeTodo(messageId, request),
    remove: (todoId) => host().mail.removeTodo(todoId),
    ownAddresses: async () => (await host().mail.mailAccounts()).map((account) => account.emailAddress),
  })

  return new TriageSession({
    waiting: (limit) => host().mail.triageWaiting(limit),
    newest: (limit) => host().mail.newestInInbox(limit),
    summaries: (threadIds) => host().mail.summaries(threadIds),
    thread: (threadId) => host().mail.thread(threadId),
    smartInboxes: () => host().mail.smartInboxes(),
    folders: () => host().mail.folders(),
    place: async (threadId, smartInboxId, verdict) => (await host().mail.classifyThread(threadId, smartInboxId, verdict, false)).placementId,
    release: (threadId, placementId) => host().mail.releaseThread(threadId, placementId),
    act: (messageIds, action) => host().run(messageIds, action),
    undoMoves: (moves) => host().undoMoves(moves),
    notify: (notice) => host().notify(notice),
    explain: errorText,
    hooks: {
      afterDo: (conversation) => (host().hooks.afterDo ?? makeTodo)(conversation),
      handOver: (conversation) => (host().hooks.handOver ?? handOver)(conversation),
    },
    changed,
  })
}

/**
 * Call on every change of the copy: the round starts once the copy holds what the server has (`synced`) and the smart
 * inboxes are there (so Spam is known), and follows the copy after that. Returns whether the round has started.
 */
export function copyChanged(session: TriageSession, started: boolean, copy: { synced: boolean; smartInboxes: number }): boolean {
  if (!copy.synced || copy.smartInboxes === 0) {
    return started
  }
  void (started ? session.follow() : session.begin())
  return true
}

/**
 * A round for a screen: `setHost` hands it the screen's latest mail, hand-overs and notices after every render, and the
 * round reads them whenever it calls them.
 */
export function createTriageDriver(changed: () => void): { session: TriageSession; setHost(host: TriageHost): void } {
  let host: TriageHost | null = null
  const session = createTriageSession(() => {
    if (host === null) {
      throw new Error('Triage started before the screen was ready.')
    }
    return host
  }, changed)
  return {
    session,
    setHost(next) {
      host = next
    },
  }
}
