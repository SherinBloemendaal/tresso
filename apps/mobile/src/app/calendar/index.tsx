import { useEffect, useMemo, useRef, useState } from 'react'
import { Pressable, ScrollView, SectionList, StyleSheet, Text, View, type ScrollViewInstance } from 'react-native'
import { Stack, useRouter } from 'expo-router'
import {
  addDays,
  daysFrom,
  inAllDayRow,
  layoutDay,
  longDate,
  occurrenceTimes,
  RESPONSE_WORDS,
  startOfWallDay,
  toWall,
  wallParts,
  WEEKDAYS_SHORT,
  weekdayOf,
  type Occurrence,
  type SyncedCalendar,
} from '@tresso/core'
import { agendaDays, occurrenceParams, weekStrip } from '@/calendar/agenda'
import { tint } from '@/calendar/colors'
import { useOccurrences, useReaderZone } from '@/calendar/useOccurrences'
import { useTheme, type Theme } from '@/theme'

/** 64 px per hour in the day view, as the mobile design has it. */
const HOUR = 64
const AGENDA_DAYS = 30

/** Agenda first, and the day view with the week strip; both read the copy on the phone. */
export default function CalendarScreen() {
  const theme = useTheme()
  const styles = useMemo(() => makeStyles(theme), [theme])
  const router = useRouter()
  const [mode, setMode] = useState<'agenda' | 'day'>('agenda')
  const [now, setNow] = useState(() => Date.now())
  const zone = useReaderZone()
  const today = startOfWallDay(toWall(now, zone))
  const [day, setDay] = useState(today)

  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 30_000)
    return () => clearInterval(timer)
  }, [])

  const range = useMemo(() => (mode === 'agenda' ? daysFrom(today, AGENDA_DAYS, zone) : daysFrom(day, 1, zone)), [mode, today, day, zone])
  const { occurrences, calendars, loaded } = useOccurrences(range[0]!.start, range[range.length - 1]!.end, zone)

  const open = (occurrence: Occurrence) => router.push({ pathname: '/calendar/event', params: occurrenceParams(occurrence) })

  return (
    <View style={styles.screen}>
      <Stack.Screen options={{ headerShown: true, title: 'Calendar', headerBackTitle: 'Inbox' }} />
      <View style={styles.modes} accessibilityRole="tablist">
        {(['agenda', 'day'] as const).map((value) => (
          <Pressable
            key={value}
            style={[styles.mode, mode === value && styles.modeOn]}
            onPress={() => setMode(value)}
            accessibilityRole="tab"
            accessibilityState={{ selected: mode === value }}
          >
            <Text style={[styles.modeLabel, mode === value && styles.modeLabelOn]}>{value === 'agenda' ? 'Agenda' : 'Day'}</Text>
          </Pressable>
        ))}
        {mode === 'day' && day !== today && (
          <Pressable style={styles.today} onPress={() => setDay(today)} accessibilityRole="button">
            <Text style={styles.todayLabel}>Today</Text>
          </Pressable>
        )}
      </View>
      {mode === 'agenda' ? (
        <Agenda zone={zone} occurrences={occurrences} days={range} today={today} now={now} calendars={calendars} loaded={loaded} styles={styles} onOpen={open} />
      ) : (
        <DayView zone={zone} day={day} today={today} now={now} occurrences={occurrences} calendars={calendars} styles={styles} theme={theme} onDay={setDay} onOpen={open} />
      )}
    </View>
  )
}

type Styles = ReturnType<typeof makeStyles>

function Agenda(props: { zone: string; occurrences: Occurrence[]; days: ReturnType<typeof daysFrom>; today: number; now: number; calendars: Map<string, SyncedCalendar>; loaded: boolean; styles: Styles; onOpen: (o: Occurrence) => void }) {
  const { styles } = props
  const sections = agendaDays(props.days, props.occurrences, props.today).map((group) => ({ key: String(group.day.wall), title: group.title, subtitle: group.subtitle, data: group.items }))
  return (
    <SectionList
      sections={sections}
      keyExtractor={(item, index) => `${item.key}:${index}`}
      stickySectionHeadersEnabled
      ListEmptyComponent={<Text style={styles.empty}>{props.loaded ? `Nothing planned in the next ${AGENDA_DAYS} days.` : 'Loading…'}</Text>}
      renderSectionHeader={({ section }) => (
        <View style={styles.sectionHead}>
          <Text style={styles.sectionTitle} accessibilityRole="header">
            {section.title}
          </Text>
          {section.subtitle !== '' && <Text style={styles.muted}>{section.subtitle}</Text>}
        </View>
      )}
      renderItem={({ item }) => <AgendaRow zone={props.zone} occurrence={item} calendar={props.calendars.get(item.event.calendarId)} now={props.now} styles={styles} onOpen={props.onOpen} />}
    />
  )
}

function AgendaRow({ zone, occurrence, calendar, now, styles, onOpen }: { zone: string; occurrence: Occurrence; calendar: SyncedCalendar | undefined; now: number; styles: Styles; onOpen: (o: Occurrence) => void }) {
  const color = calendar?.color ?? '#6E56CF'
  const response = occurrence.event.selfResponse
  const times = occurrenceTimes(occurrence, zone)
  const title = occurrence.event.summary || 'Untitled event'
  return (
    <Pressable
      style={({ pressed }) => [styles.row, occurrence.end <= now && styles.past, pressed && styles.pressed]}
      onPress={() => onOpen(occurrence)}
      accessibilityRole="button"
      accessibilityLabel={[title, times, calendar?.name, occurrence.event.location, response === null ? null : RESPONSE_WORDS[response]].filter(Boolean).join(', ')}
    >
      <View style={[styles.bar, { backgroundColor: color }]} />
      <Text style={styles.time}>{times}</Text>
      <View style={styles.texts}>
        <Text style={styles.title} numberOfLines={1}>
          {title}
        </Text>
        {(occurrence.event.location !== '' || calendar !== undefined) && (
          <Text style={styles.muted} numberOfLines={1}>
            {[occurrence.event.location, calendar?.name].filter(Boolean).join(', ')}
          </Text>
        )}
      </View>
      {response === 'needs-action' && <Text style={[styles.chip, { borderColor: color }]}>No reply</Text>}
    </Pressable>
  )
}

function DayView(props: {
  zone: string
  day: number
  today: number
  now: number
  occurrences: Occurrence[]
  calendars: Map<string, SyncedCalendar>
  styles: Styles
  theme: Theme
  onDay: (day: number) => void
  onOpen: (o: Occurrence) => void
}) {
  const { styles, day, zone } = props
  const [calendarDay] = daysFrom(day, 1, zone)
  const blocks = layoutDay(props.occurrences, calendarDay!, zone)
  const allDay = props.occurrences.filter(inAllDayRow)
  const scroller = useRef<ScrollViewInstance>(null)
  const nowParts = wallParts(toWall(props.now, zone))
  const nowTop = ((nowParts.hour * 60 + nowParts.minute) / 60) * HOUR

  useEffect(() => {
    scroller.current?.scrollTo({ y: 8 * HOUR - 8, animated: false })
  }, [day])

  return (
    <View style={styles.fill}>
      <View style={styles.strip}>
        <Pressable onPress={() => props.onDay(addDays(day, -7))} accessibilityRole="button" accessibilityLabel="Previous week" style={styles.stripArrow}>
          <Text style={styles.muted}>‹</Text>
        </Pressable>
        {weekStrip(day, zone).map((entry) => {
          const selected = entry.wall === day
          const isToday = entry.wall === props.today
          return (
            <Pressable
              key={entry.wall}
              style={styles.stripDay}
              onPress={() => props.onDay(entry.wall)}
              accessibilityRole="button"
              accessibilityLabel={longDate(entry.wall)}
              accessibilityState={{ selected }}
            >
              <Text style={styles.stripName}>{WEEKDAYS_SHORT[weekdayOf(entry.wall)]!.slice(0, 1)}</Text>
              <Text style={[styles.stripDate, isToday && styles.stripToday, selected && !isToday && styles.stripSelected]}>{wallParts(entry.wall).day}</Text>
            </Pressable>
          )
        })}
        <Pressable onPress={() => props.onDay(addDays(day, 7))} accessibilityRole="button" accessibilityLabel="Next week" style={styles.stripArrow}>
          <Text style={styles.muted}>›</Text>
        </Pressable>
      </View>
      <Text style={styles.dayTitle} accessibilityRole="header">
        {longDate(day, props.today)}
      </Text>
      {allDay.length > 0 && (
        <View style={styles.allDay}>
          {allDay.map((occurrence) => {
            const color = props.calendars.get(occurrence.event.calendarId)?.color ?? '#6E56CF'
            return (
              <Pressable
                key={occurrence.key}
                style={[styles.allDayItem, { backgroundColor: tint(color, 0.16) }]}
                onPress={() => props.onOpen(occurrence)}
                accessibilityRole="button"
                accessibilityLabel={`${occurrence.event.summary || 'Untitled event'}, ${occurrenceTimes(occurrence, zone)}`}
              >
                <Text style={styles.blockTitle} numberOfLines={1}>
                  {occurrence.event.summary || 'Untitled event'}
                </Text>
              </Pressable>
            )
          })}
        </View>
      )}
      <ScrollView ref={scroller} style={styles.fill}>
        <View style={{ height: 24 * HOUR }}>
          {Array.from({ length: 24 }, (_, hour) => (
            <View key={hour} style={[styles.hourLine, { top: hour * HOUR }]}>
              <Text style={styles.hourLabel}>{hour === 0 ? '' : `${String(hour).padStart(2, '0')}:00`}</Text>
            </View>
          ))}
          <View style={styles.columns}>
            {blocks.map((block) => {
              const color = props.calendars.get(block.occurrence.event.calendarId)?.color ?? '#6E56CF'
              const pending = block.occurrence.event.selfResponse === 'needs-action' || block.occurrence.event.selfResponse === 'tentative'
              const width = block.columns === 1 ? 100 : 65
              const left = block.columns === 1 ? 0 : (block.column * 35) / (block.columns - 1)
              return (
                <Pressable
                  key={block.occurrence.key}
                  style={[
                    styles.block,
                    {
                      top: (block.top / 60) * HOUR,
                      height: Math.max((block.height / 60) * HOUR - 2, 22),
                      left: `${left}%`,
                      width: `${width}%`,
                      borderLeftColor: color,
                      backgroundColor: pending ? props.theme.surface : tint(color, 0.16),
                      borderColor: pending ? color : 'transparent',
                      borderStyle: pending ? 'dashed' : 'solid',
                      opacity: block.occurrence.end <= props.now ? 0.55 : 1,
                    },
                  ]}
                  onPress={() => props.onOpen(block.occurrence)}
                  accessibilityRole="button"
                  accessibilityLabel={`${block.occurrence.event.summary || 'Untitled event'}, ${occurrenceTimes(block.occurrence, zone)}`}
                >
                  <Text style={styles.blockTitle} numberOfLines={1}>
                    {block.occurrence.event.summary || 'Untitled event'}
                  </Text>
                  {block.height >= 45 && <Text style={styles.blockMeta}>{occurrenceTimes(block.occurrence, zone)}</Text>}
                </Pressable>
              )
            })}
            {day === props.today && <View style={[styles.now, { top: nowTop }]} />}
          </View>
        </View>
      </ScrollView>
    </View>
  )
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    screen: { flex: 1, backgroundColor: theme.surface },
    fill: { flex: 1 },
    modes: { flexDirection: 'row', gap: 6, paddingHorizontal: 16, paddingVertical: 10, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: theme.line },
    mode: { paddingHorizontal: 14, height: 34, justifyContent: 'center', borderRadius: 8, backgroundColor: theme.ground },
    modeOn: { backgroundColor: theme.ink },
    modeLabel: { fontSize: 14, fontWeight: '600', color: theme.ink },
    modeLabelOn: { color: theme.surface },
    today: { marginLeft: 'auto', paddingHorizontal: 12, height: 34, justifyContent: 'center' },
    todayLabel: { fontSize: 14, fontWeight: '600', color: theme.accent },
    sectionHead: { flexDirection: 'row', alignItems: 'baseline', gap: 8, paddingHorizontal: 20, paddingTop: 16, paddingBottom: 6, backgroundColor: theme.surface },
    sectionTitle: { fontSize: 17, fontWeight: '700', color: theme.ink },
    row: { flexDirection: 'row', alignItems: 'center', gap: 12, minHeight: 56, paddingHorizontal: 20, paddingVertical: 8, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: theme.line },
    past: { opacity: 0.55 },
    pressed: { backgroundColor: theme.ground },
    bar: { width: 3, alignSelf: 'stretch', borderRadius: 2 },
    time: { width: 104, fontSize: 13, color: theme.ink2 },
    texts: { flex: 1, gap: 2 },
    title: { fontSize: 15, fontWeight: '600', color: theme.ink },
    muted: { fontSize: 13, color: theme.ink2 },
    chip: { fontSize: 12, fontWeight: '600', color: theme.ink2, borderWidth: 1, borderStyle: 'dashed', borderRadius: 10, paddingHorizontal: 8, paddingVertical: 2 },
    empty: { padding: 20, fontSize: 15, color: theme.ink2 },
    strip: { flexDirection: 'row', alignItems: 'center', paddingHorizontal: 8, paddingVertical: 8 },
    stripArrow: { width: 28, alignItems: 'center' },
    stripDay: { flex: 1, alignItems: 'center', gap: 4 },
    stripName: { fontSize: 12, color: theme.ink2 },
    stripDate: { width: 32, height: 32, lineHeight: 32, textAlign: 'center', borderRadius: 16, overflow: 'hidden', fontSize: 15, fontWeight: '600', color: theme.ink },
    stripToday: { backgroundColor: theme.ink, color: theme.surface },
    stripSelected: { backgroundColor: theme.ground },
    dayTitle: { paddingHorizontal: 20, paddingBottom: 8, fontSize: 17, fontWeight: '700', color: theme.ink },
    allDay: { gap: 4, paddingHorizontal: 16, paddingBottom: 8, paddingLeft: 64 },
    allDayItem: { height: 26, justifyContent: 'center', paddingHorizontal: 8, borderRadius: 6 },
    hourLine: { position: 'absolute', left: 0, right: 0, borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: theme.line },
    hourLabel: { position: 'absolute', top: -8, left: 8, width: 44, fontSize: 11, color: theme.ink2 },
    columns: { position: 'absolute', top: 0, bottom: 0, left: 56, right: 8 },
    block: { position: 'absolute', paddingHorizontal: 8, paddingVertical: 4, borderRadius: 6, borderLeftWidth: 3, borderWidth: 1 },
    blockTitle: { fontSize: 13, fontWeight: '600', color: theme.ink },
    blockMeta: { fontSize: 12, color: theme.ink2 },
    now: { position: 'absolute', left: -4, right: 0, height: 2, backgroundColor: theme.ink },
  })
}
