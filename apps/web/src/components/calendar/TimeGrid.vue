<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import {

  HOUR,
  layoutDay,
  layoutSpans,
  inAllDayRow,
  occurrenceTimes,
  shortDate,
  toInstant,
  toWall,
  WEEKDAYS_SHORT,
  wallParts,
  weekdayOf,
  type CalendarDay,
  type Occurrence,
  type SyncedCalendar,
} from '@tresso/core'
import { blockState, calendarColor, moveFocus, occurrenceLabel } from '@/calendar/look'

const props = defineProps<{
  days: CalendarDay[]
  occurrences: Occurrence[]
  zone: string
  now: number
  today: number
  calendars: (id: string) => SyncedCalendar | undefined
  selectedKey: string | null
}>()
const emit = defineEmits<{ open: [occurrence: Occurrence]; create: [start: number, allDay: boolean] }>()

/** 60 px per hour, as the design's grid has it. */
const HOUR_HEIGHT = 60
const hours = Array.from({ length: 24 }, (_, hour) => hour)

const columns = computed(() => props.days.map((day) => ({ day, blocks: layoutDay(props.occurrences, day, props.zone) })))
const spans = computed(() => layoutSpans(props.occurrences.filter(inAllDayRow), props.days))
const lanes = computed(() => Math.max(0, ...spans.value.map((item) => item.lane + 1)))

const nowWall = computed(() => toWall(props.now, props.zone))
const nowTop = computed(() => {
  const parts = wallParts(nowWall.value)
  return ((parts.hour * 60 + parts.minute) / 60) * HOUR_HEIGHT
})
const nowLabel = computed(() => {
  const parts = wallParts(nowWall.value)
  return `${String(parts.hour).padStart(2, '0')}:${String(parts.minute).padStart(2, '0')}`
})

const scroller = ref<HTMLElement | null>(null)
const grid = ref<HTMLElement | null>(null)

// Opens at 08:00, where the working day starts.
onMounted(() => {
  if (scroller.value !== null) scroller.value.scrollTop = 8 * HOUR_HEIGHT - 8
})

// Exactly one block can be reached with Tab; the arrow keys move from there.
const focusKey = ref<string | null>(null)
watch(
  () => props.occurrences,
  (list) => {
    if (focusKey.value === null || !list.some((occurrence) => occurrence.key === focusKey.value)) {
      focusKey.value = props.selectedKey ?? list.find((occurrence) => !inAllDayRow(occurrence) && occurrence.end > props.now)?.key ?? list[0]?.key ?? null
    }
  },
  { immediate: true },
)

function onKeydown(event: KeyboardEvent): void {
  if (moveFocus(event, grid.value)) {
    void nextTick(() => {
      focusKey.value = (document.activeElement as HTMLElement | null)?.dataset.key ?? focusKey.value
    })
  }
}

function blockStyle(block: { top: number; height: number; column: number; columns: number }, calendar: SyncedCalendar | undefined): Record<string, string> {
  // Overlaps cascade at 65% width; later blocks sit to the right, on top.
  const width = block.columns === 1 ? 100 : 65
  const left = block.columns === 1 ? 0 : (block.column * 35) / (block.columns - 1)
  return {
    ...calendarColor(calendar),
    top: `${(block.top / 60) * HOUR_HEIGHT}px`,
    height: `${Math.max((block.height / 60) * HOUR_HEIGHT - 2, 18)}px`,
    left: `calc(${left}% + 2px)`,
    width: `calc(${width}% - 4px)`,
    zIndex: String(block.column + 1),
  }
}

/** A click on an empty part of a day starts a new event at that half hour. */
function createAt(day: CalendarDay, event: MouseEvent): void {
  const target = event.currentTarget as HTMLElement
  const offset = event.clientY - target.getBoundingClientRect().top
  const minutes = Math.max(0, Math.min(23.5 * 60, Math.floor((offset / HOUR_HEIGHT) * 2) * 30))
  emit('create', toInstant(day.wall + minutes * 60_000, props.zone), false)
}

function isWeekend(day: CalendarDay): boolean {
  const weekday = weekdayOf(day.wall)
  return weekday === 0 || weekday === 6
}

function dayHeading(day: CalendarDay): string {
  return `${WEEKDAYS_SHORT[weekdayOf(day.wall)]} ${wallParts(day.wall).day}`
}

const hourLabel = (hour: number): string => `${String(hour).padStart(2, '0')}:00`
const isToday = (day: CalendarDay): boolean => day.wall === props.today
const dayLength = (day: CalendarDay): number => Math.round((day.end - day.start) / HOUR)
</script>

<template>
  <section ref="grid" class="time-grid" :style="{ '--days': String(days.length) }" aria-roledescription="calendar" @keydown="onKeydown">
    <div class="head" role="row">
      <span class="gutter" aria-hidden="true"></span>
      <h3 v-for="day in days" :id="`day-${day.wall}`" :key="day.wall" class="day-name" :class="{ today: isToday(day) }">
        <span class="visually-hidden">{{ shortDate(day.wall) }}</span>
        <span aria-hidden="true">{{ dayHeading(day) }}</span>
        <span v-if="dayLength(day) !== 24" class="dst" :title="`This day has ${dayLength(day)} hours`">{{ dayLength(day) }} h</span>
      </h3>
    </div>

    <div class="all-day" :style="{ '--lanes': String(Math.max(lanes, 1)) }">
      <span class="gutter label">All day</span>
      <div class="spans" @dblclick.self="(e) => emit('create', days[Math.min(days.length - 1, Math.floor(((e as MouseEvent).offsetX / (e.currentTarget as HTMLElement).clientWidth) * days.length))]!.start, true)">
        <button
          v-for="item in spans"
          :key="item.occurrence.key"
          type="button"
          class="span"
          :class="{ ...blockState(item.occurrence, now), selected: item.occurrence.key === selectedKey, 'cut-before': item.continuesBefore, 'cut-after': item.continuesAfter }"
          :style="{ ...calendarColor(calendars(item.occurrence.event.calendarId)), gridColumn: `${item.first + 1} / ${item.last + 2}`, gridRow: String(item.lane + 1) }"
          :data-occurrence="''"
          :data-key="item.occurrence.key"
          :data-column="item.first"
          :data-top="-1"
          :tabindex="focusKey === item.occurrence.key ? 0 : -1"
          :aria-label="occurrenceLabel(item.occurrence, zone, calendars(item.occurrence.event.calendarId), now)"
          @click="emit('open', item.occurrence)"
        >
          {{ item.occurrence.event.summary || 'Untitled event' }}
        </button>
      </div>
    </div>

    <div ref="scroller" class="scroller">
      <div class="body" :style="{ height: `${24 * HOUR_HEIGHT}px` }">
        <div class="hours" aria-hidden="true">
          <span v-for="hour in hours" :key="hour" class="hour" :style="{ top: `${hour * HOUR_HEIGHT}px` }">{{ hour === 0 ? '' : hourLabel(hour) }}</span>
          <span v-if="days.some(isToday)" class="now-pill" :style="{ top: `${nowTop}px` }">{{ nowLabel }}</span>
        </div>
        <div
          v-for="column in columns"
          :key="column.day.wall"
          class="column"
          :class="{ weekend: isWeekend(column.day) }"
          role="group"
          :aria-labelledby="`day-${column.day.wall}`"
          @click.self="(e) => createAt(column.day, e as MouseEvent)"
        >
          <div v-if="isToday(column.day)" class="now" :style="{ top: `${nowTop}px` }" aria-hidden="true"></div>
          <button
            v-for="block in column.blocks"
            :key="block.occurrence.key"
            type="button"
            class="block"
            :class="{ ...blockState(block.occurrence, now), selected: block.occurrence.key === selectedKey, short: block.height < 45 }"
            :style="blockStyle(block, calendars(block.occurrence.event.calendarId))"
            :data-occurrence="''"
            :data-key="block.occurrence.key"
            :data-column="columns.indexOf(column)"
            :data-top="block.top"
            :tabindex="focusKey === block.occurrence.key ? 0 : -1"
            :aria-label="occurrenceLabel(block.occurrence, zone, calendars(block.occurrence.event.calendarId), now)"
            @click="emit('open', block.occurrence)"
          >
            <span class="title">{{ block.occurrence.event.summary || 'Untitled event' }}</span>
            <span class="meta">{{ occurrenceTimes(block.occurrence, zone) }}<template v-if="block.occurrence.event.location">, {{ block.occurrence.event.location }}</template></span>
          </button>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.time-grid {
  display: grid;
  grid-template-rows: auto auto minmax(0, 1fr);
  min-height: 0;
  height: 100%;
  background: var(--surface);
}
.head,
.all-day {
  display: grid;
  grid-template-columns: 56px repeat(var(--days), minmax(0, 1fr));
  border-bottom: 1px solid var(--line);
}
.day-name {
  margin: 0;
  padding: 8px;
  font: 600 13px/18px var(--font-ui);
  color: var(--ink-2);
  border-left: 1px solid var(--line);
}
.day-name.today span[aria-hidden] {
  display: inline-block;
  padding: 0 8px;
  border-radius: 999px;
  background: var(--ink);
  color: var(--surface);
}
.dst {
  margin-left: 6px;
  font-weight: 500;
  color: var(--ink-3);
}
.gutter.label {
  padding: 6px 8px;
  font-size: 11px;
  color: var(--ink-3);
  text-align: right;
}
.all-day {
  min-height: 32px;
}
.spans {
  grid-column: 2 / -1;
  display: grid;
  grid-template-columns: repeat(var(--days), minmax(0, 1fr));
  grid-template-rows: repeat(var(--lanes), 24px);
  gap: 2px;
  padding: 4px 0;
}
.span {
  margin: 0 2px;
  padding: 0 8px;
  border: 0;
  border-radius: 6px;
  background: color-mix(in srgb, var(--cal) 16%, var(--surface));
  color: color-mix(in srgb, var(--cal) 65%, var(--ink));
  font: 600 12px/24px var(--font-ui);
  text-align: left;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  cursor: pointer;
}
.span.cut-before {
  border-top-left-radius: 0;
  border-bottom-left-radius: 0;
}
.span.cut-after {
  border-top-right-radius: 0;
  border-bottom-right-radius: 0;
}
.scroller {
  overflow-y: auto;
  min-height: 0;
}
.body {
  position: relative;
  display: grid;
  grid-template-columns: 56px repeat(var(--days), minmax(0, 1fr));
  background-image: linear-gradient(var(--line) 1px, transparent 1px);
  background-size: 100% 60px;
}
.hours {
  position: relative;
}
.hour {
  position: absolute;
  right: 8px;
  transform: translateY(-50%);
  font: 500 11px var(--font-ui);
  color: var(--ink-3);
}
.now-pill {
  position: absolute;
  right: 4px;
  transform: translateY(-50%);
  padding: 0 6px;
  border-radius: 999px;
  background: var(--ink);
  color: var(--surface);
  font: 600 11px/18px var(--font-ui);
  z-index: 3;
}
.column {
  position: relative;
  border-left: 1px solid var(--line);
}
.column.weekend {
  background: color-mix(in srgb, var(--ground) 45%, transparent);
}
.now {
  position: absolute;
  left: 0;
  right: 0;
  height: 2px;
  background: var(--ink);
  z-index: 20;
  pointer-events: none;
}
.now::before {
  content: '';
  position: absolute;
  left: -4px;
  top: -3px;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--ink);
}
.block {
  position: absolute;
  display: flex;
  flex-direction: column;
  gap: 1px;
  padding: 4px 8px 4px 10px;
  border: 0;
  border-left: 3px solid var(--cal);
  border-radius: 6px;
  background: color-mix(in srgb, var(--cal) 16%, var(--surface));
  color: color-mix(in srgb, var(--cal) 65%, var(--ink));
  outline: 1px solid var(--surface);
  text-align: left;
  overflow: hidden;
  cursor: pointer;
}
.block .title {
  font: 600 12px/16px var(--font-ui);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.block .meta {
  font: 400 11px/14px var(--font-ui);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.block.short {
  flex-direction: row;
  align-items: center;
  gap: 6px;
  padding-top: 0;
  padding-bottom: 0;
}
.block.pending,
.span.pending {
  background: var(--surface);
  border: 1px dashed var(--cal);
  border-left: 3px solid var(--cal);
}
.block.past,
.span.past {
  opacity: 0.55;
}
.block.selected,
.span.selected {
  box-shadow: 0 0 0 2px var(--focus);
}
.block:focus-visible,
.span:focus-visible {
  outline: 2px solid var(--focus);
  outline-offset: 1px;
  z-index: 30;
}
</style>
