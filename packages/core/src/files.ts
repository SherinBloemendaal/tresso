import type { ScanStatus } from './sync/types'

/** "240 KB", "1.5 MB": how large a file is, for people. */
export function fileSize(bytes: number): string {
  if (bytes < 1024) {
    return `${bytes} B`
  }
  const units = ['KB', 'MB', 'GB']
  let value = bytes / 1024
  let unit = 0
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024
    unit++
  }

  return `${value < 10 ? value.toFixed(1) : Math.round(value)} ${units[unit]}`
}

/** What the virus scan said about a file, in words. */
export const SCAN_TEXT: Record<ScanStatus, string> = {
  pending: 'not scanned yet',
  clean: 'scanned, no threats',
  infected: 'blocked: contains malware',
  failed: 'could not be scanned',
}
