import type { Address } from '@tresso/core'

export function senderName(address: Address): string {
  return address.name?.trim() || address.address
}

export function initials(address: Address): string {
  const words = senderName(address).replace(/@.*/, '').split(/[\s._-]+/).filter(Boolean)
  return ((words[0]?.[0] ?? '?') + (words.length > 1 ? (words[words.length - 1]?.[0] ?? '') : '')).toUpperCase()
}

/** "Today", "Yesterday", the weekday this week, else the date. */
export function dayLabel(epochMs: number, now = new Date()): string {
  const day = new Date(epochMs)
  const startOf = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()
  const days = Math.round((startOf(now) - startOf(day)) / 86_400_000)
  if (days === 0) return 'Today'
  if (days === 1) return 'Yesterday'
  if (days > 1 && days < 7) return day.toLocaleDateString(undefined, { weekday: 'long' })
  return day.toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: day.getFullYear() === now.getFullYear() ? undefined : 'numeric' })
}

/** The time today, else a short date. */
export function shortTime(epochMs: number, now = new Date()): string {
  const day = new Date(epochMs)
  return day.toDateString() === now.toDateString()
    ? day.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
    : day.toLocaleDateString(undefined, { day: 'numeric', month: 'short' })
}
