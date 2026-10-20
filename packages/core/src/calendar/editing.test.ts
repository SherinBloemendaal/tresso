import { describe, expect, it } from 'vitest'
import { calendarEvent } from '../store/contract'
import { deleteRequest, durationMinutes, editScopes, inputFromEvent, inputFromOccurrence, reminderInputs, saveRequest, withLength } from './editing'
import { expandOccurrences } from './occurrences'

const AMS = 'Europe/Amsterdam'
const series = calendarEvent('series', 'cal', {
  uid: 'series@tresso.test',
  start: { dateTime: '2026-10-05T09:00:00', timeZone: AMS },
  end: { dateTime: '2026-10-05T10:00:00', timeZone: AMS },
  recurrence: 'FREQ=WEEKLY;BYDAY=MO',
  defaultReminders: false,
  reminders: [
    { action: 'DISPLAY', trigger: '-PT15M', relatedTo: 'start', at: null, channels: ['phone'] },
    { action: 'DISPLAY', trigger: 'PT0S', relatedTo: 'start', at: null, channels: [] },
    { action: 'DISPLAY', trigger: '-PT5M', relatedTo: 'end', at: null, channels: ['email'] },
    { action: 'DISPLAY', trigger: null, relatedTo: 'start', at: '20261001T080000Z', channels: ['email'] },
  ],
})
const moved = calendarEvent('moved', 'cal', {
  uid: 'series@tresso.test',
  recurrenceId: '20261012T070000Z',
  start: { dateTime: '2026-10-13T14:00:00', timeZone: AMS },
  end: { dateTime: '2026-10-13T15:00:00', timeZone: AMS },
})
const [first, override, third] = expandOccurrences([series, moved], Date.UTC(2026, 9, 1), Date.UTC(2026, 9, 21), AMS)

describe('edit scopes', () => {
  it('offers nothing for an event that does not repeat', () => {
    const [single] = expandOccurrences([calendarEvent('single', 'cal')], Date.UTC(2026, 9, 1), Date.UTC(2026, 10, 1), AMS)
    expect(editScopes(single!)).toEqual([])
  })

  it('offers "this and following" only after the first occurrence', () => {
    expect(editScopes(first!)).toEqual(['this', 'all'])
    expect(editScopes(third!)).toEqual(['this', 'following', 'all'])
  })

  it('leaves out "this event" when the rule changes, and allows only the series to move calendars', () => {
    const before = inputFromOccurrence(third!, AMS)
    expect(editScopes(third!, { before, after: { ...before, recurrence: 'FREQ=DAILY' } })).toEqual(['following', 'all'])
    expect(editScopes(third!, { before, after: { ...before, calendarId: 'other' } })).toEqual(['all'])
  })
})

describe('editor values', () => {
  it('starts from the occurrence the user opened, in the event zone', () => {
    expect(inputFromOccurrence(third!, 'Asia/Tokyo')).toMatchObject({
      start: { dateTime: '2026-10-19T09:00:00', timeZone: AMS },
      end: { dateTime: '2026-10-19T10:00:00', timeZone: AMS },
      recurrence: 'FREQ=WEEKLY;BYDAY=MO',
    })
    expect(inputFromOccurrence(override!, AMS)).toMatchObject({ start: { dateTime: '2026-10-13T14:00:00', timeZone: AMS }, recurrence: 'FREQ=WEEKLY;BYDAY=MO' })
    // Saving a moved occurrence unchanged is no change of the rule, so every scope stays on offer.
    const before = inputFromOccurrence(override!, AMS)
    expect(editScopes(override!, { before, after: { ...before } })).toEqual(['this', 'following', 'all'])
  })

  it('reads reminders before the start with their channels, and leaves default reminders to the rules', () => {
    expect(reminderInputs(series)).toEqual([
      { minutesBefore: 0, channels: ['phone', 'desktop'] },
      { minutesBefore: 15, channels: ['phone'] },
    ])
    expect(inputFromEvent(series)).toMatchObject({ useDefaultReminders: false, important: false, travelMinutes: null })
    expect(inputFromEvent(calendarEvent('plain', 'cal'))).toMatchObject({ useDefaultReminders: true, reminders: [] })
    expect(durationMinutes('-P1DT2H')).toBe(-1560)
    expect(durationMinutes('-P1W')).toBe(-10080)
    expect(durationMinutes('P')).toBeNull()
  })

  it('keeps the length when the start moves', () => {
    expect(withLength({ dateTime: '2026-10-20T14:00:00', timeZone: AMS }, series.start, series.end)).toEqual({ dateTime: '2026-10-20T15:00:00', timeZone: AMS })
    expect(withLength({ date: '2026-10-22' }, { date: '2026-10-20' }, { date: '2026-10-23' })).toEqual({ date: '2026-10-25' })
  })
})

describe('requests', () => {
  it('creates new events, with notify only when it is off', () => {
    const body = inputFromOccurrence(first!, AMS)
    expect(saveRequest(body, null, true)).toMatchObject({ method: 'POST', path: '/api/v1/calendar-events' })
    expect(saveRequest(body, null, false).path).toBe('/api/v1/calendar-events?notify=false')
  })

  it('changes occurrences through their series with the scope and the occurrence', () => {
    const body = inputFromOccurrence(override!, AMS)
    expect(saveRequest(body, { occurrence: override!, scope: 'following' }, true).path).toBe('/api/v1/calendar-events/series?scope=following&recurrenceId=20261012T070000Z')
    expect(deleteRequest(third!, 'this', false).path).toBe('/api/v1/calendar-events/series?notify=false&scope=this&recurrenceId=20261019T070000Z')
  })

  it('changes a single event by its own id', () => {
    const [single] = expandOccurrences([calendarEvent('single', 'cal')], Date.UTC(2026, 9, 1), Date.UTC(2026, 10, 1), AMS)
    expect(deleteRequest(single!, 'all', true).path).toBe('/api/v1/calendar-events/single')
  })
})
