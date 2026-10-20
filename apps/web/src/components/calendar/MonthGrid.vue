<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import {
  addDays,
  cellOverflow,
  inAllDayRow,
  isoWeek,
  layoutSpans,
  longDate,
  MONTH_NAMES,
  timeIn,
  wallParts,
  WEEKDAYS_SHORT,
  weekdayOf,
  type CalendarDay,
  type Occurrence,
  type SyncedCalendar,
} from '@tresso/core'
import { blockState, calendarColor, occurrenceLabel } from '@/calendar/look'

const props = defineProps<{
  days: CalendarDay[]
  /** Any day of the month on screen. */
  month: number
  occurrences: Occurrence[]
  zone: string
  now: number
  today: number
  calendars: (id: string) => SyncedCalendar | undefined
  selectedKey: string | null
}>()
const emit = defineEmits<{ open: [occurrence: Occurrence]; showDay: [day: number]; create: [start: number, allDay: boolean] }>()

/** Three items per cell, then "+N more". */
const VISIBLE = 3

const weeks = computed(() => {
  const rows: CalendarDay[][] = []
  for (let index = 0; index < props.days.length; index += 7) {
    rows.push(props.days.slice(index, index + 7))
  }
  return rows.map((days) => {
    const items = layoutSpans(props.occurrences, days)
    return {
      days,
      cells: days.map((day, index) => ({ day, ...cellOverflow(items, index, VISIBLE) })),
    }
  })
})

const monthNumber = computed(() => wallParts(props.month).month)
const monthLabel = computed(() => `${MONTH_NAMES[monthNumber.value - 1]} ${wallParts(props.month).year}`)
const inMonth = (day: CalendarDay): boolean => wallParts(day.wall).month === monthNumber.value
const headings = computed(() => props.days.slice(0, 7).map((day) => WEEKDAYS_SHORT[weekdayOf(day.wall)]))

// The grid pattern: one cell is in the tab order, the arrow keys move between days.
const focused = ref(props.today)
watch(
  () => props.days,
  (days) => {
    if (!days.some((day) => day.wall === focused.value)) {
      focused.value = days.find((day) => day.wall === props.today)?.wall ?? days.find(inMonth)?.wall ?? days[0]?.wall ?? props.today
    }
  },
  { immediate: true },
)
const root = ref<HTMLElement | null>(null)

function onKeydown(event: KeyboardEvent): void {
  if ((event.target as HTMLElement).closest('[data-occurrence]') !== null && event.key !== 'Escape') {
    return
  }
  const moves: Record<string, number> = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }
  let next: number | null = null
  if (event.key in moves) {
    next = addDays(focused.value, moves[event.key]!)
  } else if (event.key === 'Home') {
    next = addDays(focused.value, -((weekdayOf(focused.value) + 6) % 7))
  } else if (event.key === 'End') {
    next = addDays(focused.value, 6 - ((weekdayOf(focused.value) + 6) % 7))
  } else if (event.key === 'Enter') {
    event.preventDefault()
    emit('showDay', focused.value)
    return
  }
  if (next === null || !props.days.some((day) => day.wall === next)) {
    return
  }
  event.preventDefault()
  focused.value = next
  void nextTick(() => root.value?.querySelector<HTMLElement>(`[data-day="${next}"]`)?.focus())
}

function itemTime(occurrence: Occurrence): string {
  return inAllDayRow(occurrence) ? '' : timeIn(occurrence.start, props.zone)
}
</script>

<template>
  <div ref="root" class="month" role="grid" aria-readonly="true" :aria-label="monthLabel" @keydown="onKeydown">
    <div class="row head" role="row">
      <span class="week-number" role="columnheader"><span class="visually-hidden">Week</span></span>
      <span v-for="name in headings" :key="name" role="columnheader" class="heading">{{ name }}</span>
    </div>
    <div v-for="week in weeks" :key="week.days[0]!.wall" class="row week" role="row">
      <span class="week-number" role="rowheader">{{ isoWeek(week.days[0]!.wall) }}</span>
      <div
        v-for="cell in week.cells"
        :key="cell.day.wall"
        class="cell"
        role="gridcell"
        :class="{ outside: !inMonth(cell.day), today: cell.day.wall === today }"
        :data-day="cell.day.wall"
        :tabindex="focused === cell.day.wall ? 0 : -1"
        :aria-label="`${longDate(cell.day.wall)}${cell.shown.length + cell.more === 0 ? ', nothing planned' : `, ${cell.shown.length + cell.more} ${cell.shown.length + cell.more === 1 ? 'event' : 'events'}`}`"
        :aria-current="cell.day.wall === today ? 'date' : undefined"
        @focus="focused = cell.day.wall"
        @dblclick.self="emit('create', cell.day.start, true)"
      >
        <button type="button" class="date" tabindex="-1" :aria-label="`Show ${longDate(cell.day.wall)}`" @click="emit('showDay', cell.day.wall)">{{ wallParts(cell.day.wall).day }}</button>
        <ul class="items">
          <li v-for="item in cell.shown" :key="item.occurrence.key">
            <button
              type="button"
              class="item"
              :class="{ ...blockState(item.occurrence, now), filled: inAllDayRow(item.occurrence), selected: item.occurrence.key === selectedKey }"
              :style="calendarColor(calendars(item.occurrence.event.calendarId))"
              data-occurrence=""
              tabindex="-1"
              :aria-label="occurrenceLabel(item.occurrence, zone, calendars(item.occurrence.event.calendarId), now)"
              @click="emit('open', item.occurrence)"
            >
              <span v-if="!inAllDayRow(item.occurrence)" class="dot" aria-hidden="true"></span>
              <span v-if="itemTime(item.occurrence)" class="time">{{ itemTime(item.occurrence) }}</span>
              <span class="title">{{ item.occurrence.event.summary || 'Untitled event' }}</span>
            </button>
          </li>
          <li v-if="cell.more > 0">
            <button type="button" class="more" tabindex="-1" @click="emit('showDay', cell.day.wall)">+{{ cell.more }} more</button>
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>

<style scoped>
.month {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  overflow-y: auto;
  background: var(--surface);
}
.row {
  display: grid;
  grid-template-columns: 32px repeat(7, minmax(0, 1fr));
}
.head {
  border-bottom: 1px solid var(--line);
}
.heading {
  padding: 8px;
  font: 600 12px var(--font-ui);
  color: var(--ink-2);
}
.week {
  flex: 1 0 150px;
  border-bottom: 1px solid var(--line);
}
.week-number {
  padding: 8px 4px;
  font: 500 11px var(--font-ui);
  color: var(--ink-3);
  text-align: center;
}
.cell {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  padding: 4px;
  border-left: 1px solid var(--line);
}
.cell.outside {
  background: color-mix(in srgb, var(--ground) 45%, transparent);
}
.cell.outside .date {
  color: var(--ink-3);
}
.date {
  align-self: flex-start;
  min-width: 24px;
  height: 24px;
  padding: 0 6px;
  border: 0;
  border-radius: 999px;
  background: transparent;
  font: 600 12px var(--font-ui);
  cursor: pointer;
}
.cell.today .date {
  background: var(--ink);
  color: var(--surface);
}
.items {
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin: 0;
  padding: 0;
  list-style: none;
}
.item {
  display: flex;
  align-items: center;
  gap: 4px;
  width: 100%;
  padding: 1px 6px;
  border: 0;
  border-radius: 4px;
  background: transparent;
  color: var(--ink);
  font: 400 12px/18px var(--font-ui);
  text-align: left;
  white-space: nowrap;
  overflow: hidden;
  cursor: pointer;
}
.item.filled {
  background: color-mix(in srgb, var(--cal) 16%, var(--surface));
  color: color-mix(in srgb, var(--cal) 65%, var(--ink));
  font-weight: 600;
}
.item.pending.filled {
  background: var(--surface);
  border: 1px dashed var(--cal);
}
.item.past {
  opacity: 0.55;
}
.item.selected {
  box-shadow: 0 0 0 2px var(--focus);
}
.dot {
  flex: none;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--cal);
}
.item.pending .dot {
  background: transparent;
  border: 1px solid var(--cal);
}
.time {
  color: var(--ink-2);
}
.title {
  overflow: hidden;
  text-overflow: ellipsis;
}
.more {
  padding: 0 6px;
  border: 0;
  background: transparent;
  color: var(--ink-2);
  font: 600 12px var(--font-ui);
  cursor: pointer;
}
</style>
