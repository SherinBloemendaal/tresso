import { describe, expect, it } from 'vitest'
import type { EventInput } from '@tresso/core'
import { eventSchema, inputFromValues, ruleOf, valuesFromInput, type EventValues } from '../calendarEvent'

const AMS = 'Europe/Amsterdam'

function input(extra: Partial<EventInput> = {}): EventInput {
  return {
    calendarId: 'cal',
    summary: 'Product review',
    description: '',
    location: 'Room 3.2',
    url: 'https://example.com/agenda',
    start: { dateTime: '2026-10-22T10:00:00', timeZone: AMS },
    end: { dateTime: '2026-10-22T11:00:00', timeZone: AMS },
    recurrence: null,
    status: 'tentative',
    transparency: 'busy',
    visibility: 'public',
    attendees: [{ address: 'mark@company.example', name: 'Mark Kok', role: 'optional' }],
    useDefaultReminders: false,
    reminders: [
      { minutesBefore: 15, channels: ['phone'] },
      { minutesBefore: 0, channels: ['phone', 'email'] },
    ],
    important: true,
    travelMinutes: 25,
    conferenceUrl: null,
    organizerMailAccountId: null,
    ...extra,
  }
}

function values(extra: Partial<EventValues> = {}): EventValues {
  return { ...valuesFromInput(input(), AMS), ...extra }
}

describe('event form', () => {
  it('saves what it opened unchanged, keeping the fields it does not show', () => {
    const original = input()
    expect(inputFromValues(valuesFromInput(original, AMS), { status: original.status, url: original.url })).toEqual({ ...original, reminders: [original.reminders[1], original.reminders[0]] })
  })

  it('sends no reminders of its own while the default ones apply, and a travel time only when given', () => {
    expect(inputFromValues(values({ useDefaultReminders: true }))).toMatchObject({ useDefaultReminders: true, reminders: [] })
    expect(inputFromValues(values({ travelMinutes: '' })).travelMinutes).toBeNull()
    const noChannel = eventSchema.safeParse(values({ reminders: [{ minutesBefore: 10, channels: [] }] }))
    expect(noChannel.success).toBe(false)
    expect(eventSchema.safeParse(values({ travelMinutes: '2000' })).success).toBe(false)
  })

  it('shows all-day ends inclusive and saves them exclusive, as iCalendar has them', () => {
    const allDay = input({ start: { date: '2026-10-21' }, end: { date: '2026-10-24' } })
    const shown = valuesFromInput(allDay, AMS)
    expect(shown).toMatchObject({ allDay: true, startDate: '2026-10-21', endDate: '2026-10-23' })
    expect(inputFromValues(shown)).toMatchObject({ start: { date: '2026-10-21' }, end: { date: '2026-10-24' } })
  })

  it('keeps a floating event floating', () => {
    const floating = valuesFromInput(input({ start: { dateTime: '2026-10-22T07:30:00', timeZone: null }, end: { dateTime: '2026-10-22T08:00:00', timeZone: null } }), AMS)
    expect(floating.timeZone).toBe('')
    expect(inputFromValues(floating).start).toEqual({ dateTime: '2026-10-22T07:30:00', timeZone: null })
  })

  it('writes the repeat choice with its end', () => {
    expect(ruleOf(values({ repeat: 'monthlyByDay', repeatEnd: 'until', repeatUntil: '2026-12-31' }))).toBe('FREQ=MONTHLY;BYDAY=4TH;UNTIL=20261231T225959Z')
    expect(ruleOf(values({ repeat: 'weekdays', repeatEnd: 'count', repeatCount: 5 }))).toBe('FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR;COUNT=5')
    expect(ruleOf(values({ repeat: 'none' }))).toBeNull()
    // A rule the presets cannot express is kept as it is.
    const custom = valuesFromInput(input({ recurrence: 'FREQ=WEEKLY;BYDAY=MO,TH;COUNT=8' }), AMS)
    expect(custom).toMatchObject({ repeat: 'custom', repeatEnd: 'count', repeatCount: 8 })
    expect(ruleOf(custom)).toBe('FREQ=WEEKLY;BYDAY=MO,TH;COUNT=8')
  })

  it('keeps a rule from another app exactly as stored while the repeat fields stay', () => {
    for (const recurrence of [
      // Outlook: UNTIL at the start time in UTC, INTERVAL and WKST written out.
      'FREQ=WEEKLY;UNTIL=20261231T080000Z;INTERVAL=1;BYDAY=TH;WKST=SU',
      // Apple: UNTIL one second before midnight.
      'FREQ=WEEKLY;INTERVAL=1;UNTIL=20261231T065959Z;BYDAY=TH',
      // Thunderbird: a bare weekly rule.
      'FREQ=WEEKLY',
      'FREQ=MONTHLY;BYDAY=4TH;COUNT=6',
    ]) {
      const shown = valuesFromInput(input({ recurrence }), AMS)
      expect(inputFromValues(shown).recurrence).toBe(recurrence)
      // Moving the occurrence to another day keeps the rule too (BYDAY stays on Thursday).
      expect(inputFromValues({ ...shown, startDate: '2026-10-23', endDate: '2026-10-23' }).recurrence).toBe(recurrence)
    }
  })

  it('writes a new rule once the user changes a repeat field', () => {
    const shown = valuesFromInput(input({ recurrence: 'FREQ=WEEKLY;UNTIL=20261231T080000Z;INTERVAL=1;BYDAY=TH;WKST=SU' }), AMS)
    expect(inputFromValues({ ...shown, repeatEnd: 'count', repeatCount: 3 }).recurrence).toBe('FREQ=WEEKLY;INTERVAL=1;BYDAY=TH;WKST=SU;COUNT=3')
    expect(inputFromValues({ ...shown, repeat: 'none' }).recurrence).toBeNull()
    const once = valuesFromInput(input(), AMS)
    expect(inputFromValues({ ...once, repeat: 'weekly' }).recurrence).toBe('FREQ=WEEKLY;BYDAY=TH')
  })

  it('refuses an end before the start, a bad time, a guest twice and an unsafe meeting link', () => {
    const result = eventSchema.safeParse(
      values({
        endTime: '09:00',
        startTime: '9 am',
        conferenceUrl: 'http://meet.example.com/x',
        attendees: [
          { address: 'mark@company.example', name: null, role: 'required' },
          { address: 'MARK@company.example', name: null, role: 'required' },
        ],
      }),
    )
    expect(result.success).toBe(false)
    const paths = result.success ? [] : result.error.issues.map((issue) => issue.path.join('.'))
    expect(paths).toEqual(expect.arrayContaining(['startTime', 'conferenceUrl', 'attendees.1.address']))
    expect(eventSchema.safeParse(values({ endTime: '09:30' })).success).toBe(false)
    expect(eventSchema.safeParse(values()).success).toBe(true)
  })

  it('needs a last date after the start for a series that ends', () => {
    const result = eventSchema.safeParse(values({ repeat: 'weekly', repeatEnd: 'until', repeatUntil: '2026-10-01' }))
    expect(result.success).toBe(false)
  })
})
