import { describe, expect, it } from 'vitest'
import { dayLabel, initials, senderName } from '../format'

describe('format', () => {
  it('names and initials senders', () => {
    expect(senderName({ address: 'anna@finance.example', name: '  ' })).toBe('anna@finance.example')
    expect(initials({ address: 'anna@finance.example', name: 'Anna de Vries' })).toBe('AV')
    expect(initials({ address: 'billing@finance.example', name: null })).toBe('B')
    expect(initials({ address: 'jan.smit@company.example', name: null })).toBe('JS')
  })

  it('labels days relative to now', () => {
    const now = new Date(2026, 9, 19, 12, 0)
    expect(dayLabel(new Date(2026, 9, 19, 8, 0).getTime(), now)).toBe('Today')
    expect(dayLabel(new Date(2026, 9, 18, 23, 0).getTime(), now)).toBe('Yesterday')
    expect(dayLabel(new Date(2026, 9, 1).getTime(), now)).not.toBe('Yesterday')
  })
})
