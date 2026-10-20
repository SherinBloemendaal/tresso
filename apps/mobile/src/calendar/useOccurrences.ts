import { useEffect, useState } from 'react'
import { expandOccurrences, readerTimeZone, type Occurrence, type SyncedCalendar } from '@tresso/core'
import { useMail } from '@/mail/MailProvider'

/** The phone's zone: the calendar is read in it. */
export const zone = readerTimeZone()

/**
 * The occurrences in a range of instants, expanded on the phone from the synced copy, on visible calendars and
 * without cancelled or declined ones; read again whenever the copy changes.
 */
export function useOccurrences(from: number, to: number): { occurrences: Occurrence[]; calendars: Map<string, SyncedCalendar>; loaded: boolean } {
  const { store, version } = useMail()
  const [state, setState] = useState<{ occurrences: Occurrence[]; calendars: Map<string, SyncedCalendar>; loaded: boolean }>({ occurrences: [], calendars: new Map(), loaded: false })

  useEffect(() => {
    let current = true
    const database = store()
    if (database === null) {
      return
    }
    void Promise.all([database.calendars(), database.calendarEvents(from, to)]).then(([calendars, events]) => {
      if (!current) return
      const visible = new Set(calendars.filter((calendar) => calendar.visible).map((calendar) => calendar.id))
      const occurrences = expandOccurrences(events, from, to, zone).filter(
        (occurrence) => visible.has(occurrence.event.calendarId) && occurrence.event.status !== 'cancelled' && occurrence.event.selfResponse !== 'declined',
      )
      setState({ occurrences, calendars: new Map(calendars.map((calendar) => [calendar.id, calendar])), loaded: true })
    })
    return () => {
      current = false
    }
  }, [store, version, from, to])

  return state
}
