import { describe, expect, it } from 'vitest'
import { dayValue, pickedDue } from '../handOver'

describe('hand-over dates', () => {
  it('writes a day as the local calendar has it, also just after midnight', () => {
    expect(dayValue(new Date(2026, 9, 20, 0, 5))).toBe('2026-10-20')
    expect(dayValue(new Date(2026, 9, 20, 23, 55))).toBe('2026-10-20')
    expect(dayValue(new Date(2026, 0, 3, 12))).toBe('2026-01-03')
  })

  it('reads a picked day as the end of that working day, locally', () => {
    expect(pickedDue('2026-10-24')).toEqual(new Date(2026, 9, 24, 17, 0))
    expect(pickedDue('not a day')).toBeNull()
  })
})
