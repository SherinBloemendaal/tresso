import { useCallback, useEffect, useState } from 'react'
import { AppState } from 'react-native'
import { useFocusEffect } from 'expo-router'
import { expandOccurrences, readerTimeZone, type Occurrence, type SyncedCalendar } from '@tresso/core'
import { useMail } from '@/mail/MailProvider'

/**
 * The zone the calendar is read in: the phone's, read again whenever a calendar screen comes into view and whenever
 * the app comes back to the front, so a trip or a changed system zone shows at once.
 */
export function useReaderZone(): string {
  const [zone, setZone] = useState(readerTimeZone)
  useFocusEffect(useCallback(() => setZone(readerTimeZone()), []))
  useEffect(() => {
    const subscription = AppState.addEventListener('change', (state) => {
      if (state === 'active') setZone(readerTimeZone())
    })
    return () => subscription.remove()
  }, [])
  return zone
}

/**
 * The occurrences in a range of instants, expanded on the phone from the synced copy, on visible calendars and
 * without cancelled ones; declined ones only when asked for (the event detail keeps showing an event the user just
 * declined). Read again whenever the copy or the zone changes.
 */
export function useOccurrences(
  from: number,
  to: number,
  zone: string,
  options: { includeDeclined?: boolean } = {},
): { occurrences: Occurrence[]; calendars: Map<string, SyncedCalendar>; loaded: boolean } {
  const { store, version } = useMail()
  const [state, setState] = useState<{ occurrences: Occurrence[]; calendars: Map<string, SyncedCalendar>; loaded: boolean }>({ occurrences: [], calendars: new Map(), loaded: false })
  const includeDeclined = options.includeDeclined === true

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
        (occurrence) => visible.has(occurrence.event.calendarId) && occurrence.event.status !== 'cancelled' && (includeDeclined || occurrence.event.selfResponse !== 'declined'),
      )
      setState({ occurrences, calendars: new Map(calendars.map((calendar) => [calendar.id, calendar])), loaded: true })
    })
    return () => {
      current = false
    }
  }, [store, version, from, to, zone, includeDeclined])

  return state
}
