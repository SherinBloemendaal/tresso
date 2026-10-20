import type { HandedOver } from '@tresso/core'

/**
 * Who waits for the hand-over screen of a conversation to close (triage): settled with the hand-over, or with null when
 * the screen closed without one. One waiter per conversation; a newer one settles the older with null.
 */
export class HandOverWaiters {
  private readonly waiting = new Map<string, (handed: HandedOver | null) => void>()

  wait(threadId: string): Promise<HandedOver | null> {
    this.settle(threadId, null)
    return new Promise((resolve) => this.waiting.set(threadId, resolve))
  }

  /** Whether someone waits for this conversation (then they give the notice, not the hand-over screen). */
  has(threadId: string): boolean {
    return this.waiting.has(threadId)
  }

  /** Settles the waiter of this conversation, if any. */
  settle(threadId: string, handed: HandedOver | null): void {
    const resolve = this.waiting.get(threadId)
    this.waiting.delete(threadId)
    resolve?.(handed)
  }
}
