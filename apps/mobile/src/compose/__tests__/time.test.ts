import { describe, expect, it } from 'vitest'
import { isFutureTime } from '../time'

describe('isFutureTime', () => {
  it('takes only times at least a minute ahead', () => {
    const now = Date.parse('2026-10-19T09:00:00Z')
    expect(isFutureTime(new Date(now + 60_000), now)).toBe(true)
    expect(isFutureTime(new Date(now + 59_000), now)).toBe(false)
    expect(isFutureTime(new Date(now - 1), now)).toBe(false)
  })
})
