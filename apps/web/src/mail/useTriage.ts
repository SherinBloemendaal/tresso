import { computed, onScopeDispose, shallowRef, triggerRef, watch } from 'vue'
import { TriageSession, currentTriage, todoOnDo, triageProgress, withdrawnNotice, type TriageConversation, type TriageDecision, type TriageHooks, type TriageUndo } from '@tresso/core'
import { ApiError, errorMessage } from '@/api/errors'
import { useMessageActions } from '@/mail/useMessageActions'
import { useComposeStore } from '@/stores/compose'
import { useMailStore } from '@/stores/mail'
import { useHandOversStore } from '@/stores/handOvers'
import { useTodosStore } from '@/stores/todos'

export type { TriageConversation, TriageHooks, TriageUndo } from '@tresso/core'

/**
 * Triage as the web client runs it: a round over the conversations waiting now, one at a time (see `TriageSession` in
 * `@tresso/core`, which the phone runs too). The round starts once the copy has pulled what the server has and the
 * smart inboxes are there, and follows every change of the copy after that.
 *
 * @param hooks where other features plug in; by default do makes the conversation's todo (see `todoOnDo`) and hand
 *   over opens the hand-over dialog
 */
export function useTriage(hooks: Partial<TriageHooks> = {}) {
  const mail = useMailStore()
  const compose = useComposeStore()
  const actions = useMessageActions()
  const todos = useTodosStore()
  const handOvers = useHandOversStore()

  const makeTodo = todoOnDo({
    makeFromMessage: (messageId, request) => todos.makeFromMessage(messageId, request),
    remove: async (todoId) => {
      try {
        await todos.remove(todoId)
      } catch (error) {
        // Removed meanwhile (on another device, say): what undo wanted.
        if (!(error instanceof ApiError && error.status === 404)) {
          throw error
        }
      }
    },
    ownAddresses: async () => mail.accounts.map((account) => account.emailAddress),
  })

  /**
   * Hand over through the hand-over dialog (it forwards with a note and a due date, tracked in Waiting on). It settles
   * once the dialog closes: null when the user closed it without handing over. Undo takes the forward back while it has
   * not left, and the hand-over in any case.
   */
  async function handOver(conversation: TriageConversation): Promise<TriageUndo | null> {
    const handed = await handOvers.handOverAndWait(conversation.messages)
    if (handed === null) {
      return null
    }
    return {
      undo: async () => {
        if ((await handOvers.takeBack(handed)) === 'withdrawn') {
          compose.notify({ text: withdrawnNotice(handed), tone: 'info' })
        }
      },
    }
  }

  const session = shallowRef(
    new TriageSession({
      waiting: (limit) => mail.triageWaiting(limit),
      newest: (limit) => mail.newestInInbox(limit),
      summaries: (threadIds) => mail.summaries(threadIds),
      thread: (threadId) => mail.thread(threadId),
      smartInboxes: async () => mail.smartInboxes,
      folders: async () => mail.allFolders,
      place: async (threadId, smartInboxId, verdict) => (await mail.classifyThread(threadId, smartInboxId, verdict, { remember: false })).placementId,
      release: (threadId, placementId) => mail.releaseThread(threadId, placementId),
      act: (messageIds, action) => actions.run(messageIds, action),
      undoMoves: (moves) => actions.undo(moves),
      notify: (notice) => compose.notify({ text: notice.text, tone: notice.tone, action: notice.action === undefined ? undefined : { label: notice.action.label, run: notice.action.run } }),
      explain: errorMessage,
      hooks: { afterDo: hooks.afterDo ?? makeTodo, handOver: hooks.handOver ?? handOver },
      changed: () => triggerRef(session),
    }),
  )

  const state = computed(() => session.value.state)
  /** The conversation the round is at (null once it is over); `conversation` catches up once it is loaded. */
  const current = computed(() => (session.value.state === null ? null : currentTriage(session.value.state)))
  const conversation = computed(() => (session.value.conversation?.thread.id === current.value ? session.value.conversation : null))
  const progress = computed(() => (session.value.state === null ? null : triageProgress(session.value.state)))
  const canUndo = computed(() => session.value.canUndo)
  /** Decisions and undos still being saved; keys keep working meanwhile. */
  const saving = computed(() => session.value.pending > 0)
  const more = computed(() => session.value.more)

  let started = false
  let disposed = false
  onScopeDispose(() => {
    disposed = true
  })

  // Starts once the copy holds what the server has (a new browser, mail that came in while offline) and the smart
  // inboxes are there (so Spam is known); follows every change of the copy after that.
  watch(
    [() => mail.synced, () => mail.smartInboxes.length > 0, () => mail.conversations],
    ([synced, smartInboxes]) => {
      if (disposed || !synced || !smartInboxes) {
        return
      }
      if (!started) {
        started = true
        void session.value.begin()
        return
      }
      void session.value.follow()
    },
    { immediate: true },
  )

  return {
    state,
    current,
    conversation,
    progress,
    canUndo,
    saving,
    more,
    decide: (decision: TriageDecision) => session.value.decide(decision),
    skip: () => session.value.skip(),
    undo: () => session.value.undo(),
    nextRound: () => session.value.begin(),
    /** For tests: settles once every decision and undo asked for so far is done. */
    idle: () => session.value.idle(),
  }
}
