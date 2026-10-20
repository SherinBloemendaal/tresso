import { longDate, occurrenceWhen, RESPONSE_WORDS, todoBlockTimes, toWall, type Occurrence, type SyncedCalendar, type TodoBlock } from '@tresso/core'

/** The default calendar color when a calendar has none the browser understands. */
const FALLBACK = '#6E56CF'

/** The calendar's color as a CSS custom property; fills and text are mixed from it in the stylesheet. */
export function calendarColor(calendar: SyncedCalendar | undefined): Record<string, string> {
  const color = calendar?.color && /^#[0-9a-f]{3,8}$/i.test(calendar.color) ? calendar.color : FALLBACK
  return { '--cal': color }
}

/** Time planned for a todo takes the Do colors, the one verdict color the calendar shows. */
export const TODO_COLOR: Record<string, string> = { '--cal': 'var(--do)' }

/** What a screen reader hears for the time a todo is planned at. */
export function todoBlockLabel(block: TodoBlock, zone: string, now: number): string {
  const parts = [`Todo: ${block.todo.title}`, `${longDate(toWall(block.start, zone), toWall(now, zone))}, ${todoBlockTimes(block, zone)}`]
  if (block.conflict) parts.push('that time is taken')
  return parts.join(', ')
}

/** How a block looks: no reply or maybe is dashed, an event that ended today is faded. */
export function blockState(occurrence: Occurrence, now: number): { pending: boolean; past: boolean } {
  const response = occurrence.event.selfResponse
  return { pending: response === 'needs-action' || response === 'tentative', past: occurrence.end <= now }
}

/** What a screen reader hears for an event block. */
export function occurrenceLabel(occurrence: Occurrence, zone: string, calendar: SyncedCalendar | undefined, now: number): string {
  const parts = [occurrence.event.summary || 'Untitled event', occurrenceWhen(occurrence, zone, toWall(now, zone))]
  if (calendar !== undefined) parts.push(calendar.name)
  if (occurrence.event.location !== '') parts.push(occurrence.event.location)
  if (occurrence.event.selfResponse !== null) parts.push(RESPONSE_WORDS[occurrence.event.selfResponse])
  if (occurrence.recurring) parts.push('repeats')
  return parts.join(', ')
}

/** The button label of an online meeting: "Join Teams meeting", "Join Google Meet". */
export function joinLabel(provider: string): string {
  switch (provider) {
    case 'teams':
      return 'Join Teams meeting'
    case 'meet':
      return 'Join Google Meet'
    case 'zoom':
      return 'Join Zoom meeting'
    case 'webex':
      return 'Join Webex meeting'
    default:
      return 'Join meeting'
  }
}

/** Moves focus among the event blocks of a view with the arrow keys; true when it handled the key. */
export function moveFocus(event: KeyboardEvent, container: HTMLElement | null): boolean {
  if (container === null || !['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
    return false
  }
  const items = [...container.querySelectorAll<HTMLElement>('[data-occurrence]')]
  const current = items.indexOf(document.activeElement as HTMLElement)
  if (items.length === 0 || current === -1) {
    return false
  }
  const here = items[current]!
  const column = Number(here.dataset.column ?? 0)
  let target: HTMLElement | undefined
  switch (event.key) {
    case 'ArrowUp':
      target = items.slice(0, current).reverse().find((item) => Number(item.dataset.column ?? 0) === column)
      break
    case 'ArrowDown':
      target = items.slice(current + 1).find((item) => Number(item.dataset.column ?? 0) === column)
      break
    case 'ArrowLeft':
    case 'ArrowRight': {
      // The nearest event in the next day with any, at about the same time.
      const direction = event.key === 'ArrowLeft' ? -1 : 1
      const top = Number(here.dataset.top ?? 0)
      const columns = [...new Set(items.map((item) => Number(item.dataset.column ?? 0)))].sort((a, b) => a - b)
      const next = direction === 1 ? columns.find((value) => value > column) : columns.reverse().find((value) => value < column)
      if (next !== undefined) {
        const candidates = items.filter((item) => Number(item.dataset.column ?? 0) === next)
        target = candidates.reduce((best, item) => (Math.abs(Number(item.dataset.top ?? 0) - top) < Math.abs(Number(best.dataset.top ?? 0) - top) ? item : best), candidates[0]!)
      }
      break
    }
    case 'Home':
      target = items[0]
      break
    case 'End':
      target = items[items.length - 1]
      break
  }
  if (target !== undefined) {
    for (const item of items) item.tabIndex = -1
    target.tabIndex = 0
    target.focus()
  }
  event.preventDefault()
  return true
}
