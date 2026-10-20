import { daysFrom, relativeDay, shortDate, startOfWeek, type CalendarDay, type Occurrence, type TodoBlock } from '@tresso/core'

/** A day of the agenda with what touches it; a multi-day event is listed on each of its days. */
export interface AgendaDay {
  day: CalendarDay
  title: string
  subtitle: string
  items: (Occurrence | TodoBlock)[]
}

/**
 * Groups occurrences by the days they touch, leaving out days with nothing on them. Time planned for todos goes among
 * the events of its day by its start, after the all-day events.
 */
export function agendaDays(days: CalendarDay[], occurrences: Occurrence[], today: number, todoBlocks: TodoBlock[] = []): AgendaDay[] {
  return days
    .map((day) => {
      const title = relativeDay(day.wall, today)
      return {
        day,
        title,
        subtitle: title === 'Today' || title === 'Tomorrow' || title === 'Yesterday' ? shortDate(day.wall) : '',
        items: [
          ...occurrences.filter((occurrence) => (occurrence.end === occurrence.start ? occurrence.start >= day.start && occurrence.start < day.end : occurrence.start < day.end && occurrence.end > day.start)),
          ...todoBlocks.filter((block) => block.start < day.end && block.end > day.start),
        ].sort((a, b) => Number(!a.allDay) - Number(!b.allDay) || a.start - b.start),
      }
    })
    .filter((group) => group.items.length > 0)
}

/** The seven days of the week strip around a day, starting on Monday. */
export function weekStrip(day: number, zone: string): CalendarDay[] {
  return daysFrom(startOfWeek(day), 7, zone)
}

/** How a route names an occurrence: its key and the instant it starts, so the detail screen can find it again. */
export function occurrenceParams(occurrence: Occurrence): { key: string; start: string } {
  return { key: occurrence.key, start: String(occurrence.start) }
}
