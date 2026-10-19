import { computed, ref } from 'vue'
import { defineStore } from 'pinia'

/**
 * The conversations the user selected in the list to act on together. While any are selected, the conversation's
 * own shortcuts give way to the selection's.
 */
export const useSelectionStore = defineStore('selection', () => {
  const threadIds = ref(new Set<string>())
  /** The row the last plain click went to, where a shift click selects up to. */
  const anchor = ref<string | null>(null)
  const active = computed(() => threadIds.value.size > 0)
  /** A bulk action is on its way; kept here so it holds while the bar is gone and back. */
  const busy = ref(false)

  function has(threadId: string): boolean {
    return threadIds.value.has(threadId)
  }

  function toggle(threadId: string): void {
    const next = new Set(threadIds.value)
    if (!next.delete(threadId)) {
      next.add(threadId)
    }
    threadIds.value = next
    anchor.value = threadId
  }

  /** Selects every row from the anchor to this one, in the order shown. */
  function extend(threadId: string, order: string[]): void {
    const from = anchor.value === null ? -1 : order.indexOf(anchor.value)
    const to = order.indexOf(threadId)
    if (from === -1 || to === -1) {
      toggle(threadId)
      return
    }
    const next = new Set(threadIds.value)
    for (const id of order.slice(Math.min(from, to), Math.max(from, to) + 1)) {
      next.add(id)
    }
    threadIds.value = next
    anchor.value = threadId
  }

  function remove(ids: string[]): void {
    const next = new Set(threadIds.value)
    for (const id of ids) {
      next.delete(id)
    }
    threadIds.value = next
  }

  function add(ids: string[]): void {
    threadIds.value = new Set([...threadIds.value, ...ids])
  }

  function set(ids: string[]): void {
    threadIds.value = new Set(ids)
  }

  function clear(): void {
    threadIds.value = new Set()
    anchor.value = null
  }

  return { threadIds, anchor, active, busy, has, toggle, extend, add, remove, set, clear }
})
