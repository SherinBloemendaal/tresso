import type { Address } from '@tresso/core'

export function senderName(address: Address): string {
  return address.name?.trim() || address.address
}

/** "Today", "Yesterday", or the date, for grouping a list by day. */
export function dayLabel(epochMs: number, now = new Date()): string {
  const date = new Date(epochMs)
  const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime()
  const day = 86_400_000
  if (epochMs >= startOfToday) {
    return 'Today'
  }
  if (epochMs >= startOfToday - day) {
    return 'Yesterday'
  }
  const sameYear = date.getFullYear() === now.getFullYear()

  return date.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', ...(sameYear ? {} : { year: 'numeric' }) })
}

/** Time for today, otherwise a short date. */
export function shortTime(epochMs: number, now = new Date()): string {
  const date = new Date(epochMs)
  if (date.toDateString() === now.toDateString()) {
    return date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
  }

  return date.toLocaleDateString(undefined, { day: 'numeric', month: 'short', ...(date.getFullYear() === now.getFullYear() ? {} : { year: 'numeric' }) })
}

export function fullTime(iso: string): string {
  return new Date(iso).toLocaleString(undefined, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

export { fileSize } from '@tresso/core'
