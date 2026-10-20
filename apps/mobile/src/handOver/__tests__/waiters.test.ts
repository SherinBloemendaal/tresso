import { describe, expect, it } from 'vitest'
import type { HandedOver } from '@tresso/core'
import { HandOverWaiters } from '../waiters'

const handed = { forwardId: 'f1', draftId: 'd1', until: 0, handOver: { id: 'h1' } } as unknown as HandedOver

describe('HandOverWaiters', () => {
  it('settles with the hand-over, once', async () => {
    const waiters = new HandOverWaiters()
    const waiting = waiters.wait('t1')
    expect(waiters.has('t1')).toBe(true)

    waiters.settle('t1', handed)
    waiters.settle('t1', null)

    expect(await waiting).toBe(handed)
    expect(waiters.has('t1')).toBe(false)
  })

  it('settles with null when the screen closes without a hand-over, and when a newer wait replaces it', async () => {
    const waiters = new HandOverWaiters()
    const first = waiters.wait('t1')
    const second = waiters.wait('t1')
    expect(await first).toBeNull()

    waiters.settle('t1', null)
    expect(await second).toBeNull()
  })
})
