import { describe, expect, it } from 'vitest'
import { formatDateTime, offsetAt, offsetLabel, parseWall, recurrenceKey, toInstant, toWall, utc, zoneLabel } from './zones'

const wall = (value: string): number => parseWall(value)!

describe('time zones', () => {
  it('reads the clock in a zone and back', () => {
    const instant = Date.UTC(2026, 9, 20, 8, 24)
    expect(formatDateTime(toWall(instant, 'Europe/Amsterdam'))).toBe('2026-10-20T10:24:00')
    expect(toInstant(wall('2026-10-20T10:24'), 'Europe/Amsterdam')).toBe(instant)
    expect(toInstant(wall('2026-10-20T10:24'), 'America/New_York')).toBe(Date.UTC(2026, 9, 20, 14, 24))
  })

  it('reads a time that happens twice when clocks go back as the later one, as the server does', () => {
    // Amsterdam goes from 03:00 CEST back to 02:00 CET on 25 October 2026; the server reads 02:30 as 02:30 CET.
    expect(toInstant(wall('2026-10-25T02:30'), 'Europe/Amsterdam')).toBe(Date.UTC(2026, 9, 25, 1, 30))
    expect(toInstant(wall('2026-10-25T03:30'), 'Europe/Amsterdam')).toBe(Date.UTC(2026, 9, 25, 2, 30))
    expect(toInstant(wall('2026-10-25T01:30'), 'Europe/Amsterdam')).toBe(Date.UTC(2026, 9, 24, 23, 30))
  })

  it('moves a time that never happens when clocks go forward past the change', () => {
    // On 29 March 2026 the clock jumps from 02:00 to 03:00; 02:30 is read with the winter offset, so it is 03:30.
    const instant = toInstant(wall('2026-03-29T02:30'), 'Europe/Amsterdam')
    expect(instant).toBe(Date.UTC(2026, 2, 29, 1, 30))
    expect(formatDateTime(toWall(instant, 'Europe/Amsterdam'))).toBe('2026-03-29T03:30:00')
  })

  it('knows offsets that are not whole hours', () => {
    expect(offsetAt(Date.UTC(2026, 0, 1), 'Asia/Kolkata')).toBe(330)
    expect(offsetLabel(330)).toBe('UTC+5:30')
    expect(offsetLabel(-240)).toBe('UTC-4')
    expect(offsetLabel(0)).toBe('UTC')
  })

  it('names a zone city first', () => {
    expect(zoneLabel('Europe/Amsterdam', Date.UTC(2026, 9, 20))).toBe('Amsterdam, CEST (UTC+2)')
    expect(zoneLabel('Europe/Amsterdam', Date.UTC(2026, 11, 20))).toBe('Amsterdam, CET (UTC+1)')
    expect(zoneLabel('America/Argentina/Buenos_Aires', Date.UTC(2026, 9, 20))).toMatch(/^Buenos Aires.*\(UTC-3\)$/)
    expect(zoneLabel('UTC', 0)).toBe('UTC')
  })

  it('writes recurrence ids as the API does', () => {
    expect(recurrenceKey(Date.UTC(2026, 9, 19, 7, 0), false)).toBe('20261019T070000Z')
    expect(recurrenceKey(Date.UTC(2026, 9, 19), true)).toBe('20261019')
  })

  it('keeps the first centuries apart from the 1900s', () => {
    expect(new Date(utc(1, 0, 1)).toISOString()).toBe('0001-01-01T00:00:00.000Z')
    expect(new Date(utc(99, 11, 32)).toISOString()).toBe('0100-01-01T00:00:00.000Z')
    expect(formatDateTime(toWall(utc(1, 0, 1, 12), 'UTC'))).toBe('0001-01-01T12:00:00')
    expect(parseWall('0001-01-01T09:00')).toBe(utc(1, 0, 1, 9))
  })

  it('refuses dates that do not exist', () => {
    expect(parseWall('2026-02-30')).toBeNull()
    expect(parseWall('tomorrow')).toBeNull()
  })
})
