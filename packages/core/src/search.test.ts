import { describe, expect, it } from 'vitest'
import { searchDay, searchFilters, withSearchFilter } from './search'

describe('searchFilters', () => {
  it('finds the filters among the words', () => {
    expect(searchFilters('budget from:anna HAS:attachment in:"Work stuff" https://x.example')).toEqual([
      { name: 'from', value: 'anna', raw: 'from:anna' },
      { name: 'has', value: 'attachment', raw: 'HAS:attachment' },
      { name: 'in', value: 'Work stuff', raw: 'in:"Work stuff"' },
    ])
  })

  it('ignores filters without a value', () => {
    expect(searchFilters('from: budget')).toEqual([])
  })
})

describe('withSearchFilter', () => {
  it('replaces a filter and keeps the rest', () => {
    expect(withSearchFilter('budget in:inbox from:anna', 'in', 'kim@home.example')).toBe('budget from:anna in:kim@home.example')
  })

  it('removes a filter', () => {
    expect(withSearchFilter('budget has:attachment', 'has', null)).toBe('budget')
    expect(withSearchFilter('IN:inbox budget in:archive', 'in', null)).toBe('budget')
  })

  it('quotes values with spaces', () => {
    expect(withSearchFilter('', 'from', 'Anna de Vries')).toBe('from:"Anna de Vries"')
  })

  it('leaves quoted phrases alone', () => {
    expect(withSearchFilter('"team day" budget', 'has', 'attachment')).toBe('"team day" budget has:attachment')
  })
})

describe('searchDay', () => {
  it('counts back in local days', () => {
    expect(searchDay(new Date(2026, 9, 19, 23, 30))).toBe('2026-10-19')
    expect(searchDay(new Date(2026, 9, 19, 9), 30)).toBe('2026-09-19')
    expect(searchDay(new Date(2026, 0, 5), 7)).toBe('2025-12-29')
  })
})
