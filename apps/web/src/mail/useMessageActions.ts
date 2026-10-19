import { ref } from 'vue'
import type { MessageAction, SyncedFolder } from '@tresso/core'
import { errorMessage } from '@/api/errors'
import type { AppliedAction } from '@/mail/db'
import { undoPlan, type UndoMove } from '@/mail/undo'
import { useComposeStore } from '@/stores/compose'
import { useMailStore } from '@/stores/mail'

/**
 * Message actions as screens use them: errors become notices, one action runs at a time, and archiving, trashing or
 * moving offers an undo that puts back exactly what moved (none when that is not possible).
 */
export function useMessageActions() {
  const mail = useMailStore()
  const compose = useComposeStore()
  /** An action is on its way; buttons and shortcuts wait for it. */
  const busy = ref(false)
  let before: Promise<void> = Promise.resolve()

  /** Lets actions taken from now on wait for this first (e.g. reading a conversation on open). */
  function after(task: Promise<unknown>): void {
    before = task.then(
      () => undefined,
      () => undefined,
    )
  }

  async function run(messageIds: string[], action: MessageAction, folderId?: string): Promise<AppliedAction | null> {
    try {
      return await mail.act(messageIds, action, { folderId })
    } catch (error) {
      compose.notify({ text: errorMessage(error), tone: 'error' })
      return null
    }
  }

  /**
   * Runs one action at a time: a second click or a held key during an action is ignored rather than acting on its
   * result. An action waits for what {@link after} was given, then runs.
   */
  async function exclusive(task: () => Promise<void>): Promise<void> {
    if (busy.value) {
      return
    }
    busy.value = true
    try {
      await before
      await task()
    } finally {
      busy.value = false
    }
  }

  /** Archives, trashes or moves the messages and says so, with an undo when one puts them back exactly. */
  async function relocate(messageIds: string[], action: 'archive' | 'trash' | 'move', folders: SyncedFolder[], folderId?: string): Promise<boolean> {
    const applied = await run(messageIds, action, folderId)
    if (applied === null) {
      return false
    }
    const label = action === 'archive' ? 'Archived' : action === 'trash' ? 'Moved to Trash' : `Moved to ${folders.find((folder) => folder.id === folderId)?.displayName ?? 'the folder'}`
    const plan = undoPlan(applied, action, folders)
    compose.notify({
      text: `${label}.`,
      tone: 'info',
      action: plan.exact && plan.moves.length > 0 ? { label: 'Undo', run: () => void undo(plan.moves) } : undefined,
    })
    return true
  }

  async function undo(moves: UndoMove[]): Promise<void> {
    let failed = 0
    let reason = ''
    for (const move of moves) {
      try {
        await mail.act(move.messageIds, 'move', { folderId: move.folderId, fromFolderId: move.fromFolderId })
      } catch (error) {
        failed += move.messageIds.length
        reason = errorMessage(error)
      }
    }
    if (failed > 0) {
      compose.notify({ text: `${failed === 1 ? 'One message' : `${failed} messages`} could not be put back: ${reason}`, tone: 'error' })
    }
  }

  return { busy, after, run, exclusive, relocate }
}

/** Whether a key press is meant for shortcuts: not typing, not writing, not held, no modifiers. */
export function shortcutAllowed(event: KeyboardEvent, composing: boolean): boolean {
  const target = event.target instanceof HTMLElement ? event.target : null
  const typing = target !== null && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))
  const inDialog = target?.closest('[role="dialog"]') != null
  return !(typing || composing || inDialog || event.repeat || event.isComposing || event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey)
}
