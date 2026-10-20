import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import {
  bulkActionRunning,
  bulkPollDelay,
  bulkProgressText,
  bulkResultText,
  bulkSelectionQuery,
  type BulkActionRequest,
  type BulkActionState,
  type BulkScope,
  type BulkSelectionCount,
  type CleanupSuggestionList,
  type SmartInboxSenders,
} from '@tresso/core'
import { errorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useComposeStore } from '@/stores/compose'
import { useMailStore } from '@/stores/mail'

/** How long the notice with the undo stays, in ms: a bulk action is bigger than one conversation, so a little longer. */
const RESULT_NOTICE_MS = 15_000

/**
 * Bulk actions on a whole scope (a smart inbox, one of its senders, mail older than an age, a cleanup suggestion).
 * The server works through them in batches; this follows one until it is done, says how it went with an undo, and
 * pulls the synced copy, whose lists also change batch by batch through the delta meanwhile. One at a time: kept
 * here rather than in a screen, so it goes on when the user moves elsewhere.
 */
export const useBulkStore = defineStore('bulk', () => {
  const auth = useAuthStore()
  const compose = useComposeStore()
  const mail = useMailStore()

  /** The action (or undo) the server is working through. */
  const running = ref<BulkActionState | null>(null)
  /** Asking the server to start one: a second click meanwhile must not start another. */
  const starting = ref(false)
  /** Whether a bulk action is starting or under way; screens disable their buttons meanwhile. */
  const busy = computed(() => starting.value || running.value !== null)
  const progress = computed(() => (running.value === null ? (starting.value ? 'Starting…' : null) : bulkProgressText(running.value)))

  function count(scope: BulkScope): Promise<BulkSelectionCount> {
    return auth.api<BulkSelectionCount>(`/api/v1/bulk-selection?${bulkSelectionQuery(scope)}`)
  }

  function senders(smartInboxId: string): Promise<SmartInboxSenders> {
    return auth.api<SmartInboxSenders>(`/api/v1/smart-inboxes/${encodeURIComponent(smartInboxId)}/senders`)
  }

  async function suggestions(): Promise<CleanupSuggestionList> {
    return auth.api<CleanupSuggestionList>('/api/v1/cleanup-suggestions')
  }

  /**
   * Starts the action and follows it until it is done. Returns how it ended, or null when it did not start (another
   * one is running, or the server refused it; that is said in a notice).
   *
   * @param folderName the folder a move goes to, for the notice
   */
  async function start(request: BulkActionRequest, folderName?: string): Promise<BulkActionState | null> {
    if (busy.value) {
      compose.notify({ text: 'Another bulk action is still under way. Try again once it is done.', tone: 'error' })
      return null
    }
    starting.value = true
    try {
      const state = await follow(await auth.send<BulkActionState>('POST', '/api/v1/bulk-actions', request))
      compose.notify(
        {
          text: bulkResultText(state, folderName),
          tone: state.status === 'failed' ? 'error' : 'info',
          action: state.canUndo && state.changed > state.unrestorable ? { label: 'Undo', run: () => void undo(state.id) } : undefined,
        },
        RESULT_NOTICE_MS,
      )
      return state
    } catch (error) {
      compose.notify({ text: errorMessage(error), tone: 'error' })
      return null
    } finally {
      starting.value = false
    }
  }

  /** Puts back what the action moved, the way an undo of a single action does. */
  async function undo(id: string): Promise<BulkActionState | null> {
    if (busy.value) {
      compose.notify({ text: 'Wait for the action under way to finish, then undo.', tone: 'error' })
      return null
    }
    starting.value = true
    try {
      const state = await follow(await auth.send<BulkActionState>('POST', `/api/v1/bulk-actions/${encodeURIComponent(id)}/undo`, {}))
      compose.notify({ text: bulkResultText(state), tone: state.status === 'failed' ? 'error' : 'info' })
      return state
    } catch (error) {
      compose.notify({ text: `Could not undo: ${errorMessage(error)}`, tone: 'error' })
      return null
    } finally {
      starting.value = false
    }
  }

  /** Asks how the action is doing, soon at first and then less often, until the server stopped working on it. */
  async function follow(first: BulkActionState): Promise<BulkActionState> {
    let state = first
    running.value = state
    starting.value = false
    try {
      for (let attempt = 0; bulkActionRunning(state); attempt++) {
        await new Promise((resolve) => setTimeout(resolve, bulkPollDelay(attempt)))
        state = await auth.api<BulkActionState>(`/api/v1/bulk-actions/${encodeURIComponent(state.id)}`)
        running.value = state
      }
    } finally {
      running.value = null
    }
    await mail.syncNow()

    return state
  }

  return { running, busy, progress, count, senders, suggestions, start, undo }
})
