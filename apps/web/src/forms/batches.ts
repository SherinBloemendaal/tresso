import { z } from 'zod'
import { BATCH_TIME_PATTERN, MAX_BATCH_TIMES } from '@tresso/core'

/** Whether this runtime knows the IANA time zone (the API checks the same). */
export function isTimeZone(zone: string): boolean {
  try {
    Intl.DateTimeFormat(undefined, { timeZone: zone }).resolvedOptions()
    return true
  } catch {
    return false
  }
}

/** The time zone this browser runs in, to offer when the user has not picked one. */
export function browserTimeZone(): string {
  return Intl.DateTimeFormat().resolvedOptions().timeZone
}

/** Every IANA time zone this runtime knows; an engine without `Intl.supportedValuesOf` offers the browser's own. */
export function timeZones(): string[] {
  const intl = Intl as typeof Intl & { supportedValuesOf?: (key: 'timeZone') => string[] }

  return intl.supportedValuesOf?.('timeZone') ?? [browserTimeZone()]
}

export const batchScheduleSchema = z.object({
  times: z
    .array(z.string().regex(BATCH_TIME_PATTERN, 'Use a time like 09:00.'))
    .min(1, 'Add at least one batch time.')
    .max(MAX_BATCH_TIMES, `Use at most ${MAX_BATCH_TIMES} batch times.`)
    .superRefine((times, context) => {
      times.forEach((time, index) => {
        if (times.indexOf(time) !== index) {
          context.addIssue({ code: z.ZodIssueCode.custom, path: [index], message: `${time} is already a batch time.` })
        }
      })
    }),
  timeZone: z.string().min(1, 'Pick a time zone.').refine(isTimeZone, 'Pick a time zone from the list.'),
})

export type BatchScheduleValues = z.infer<typeof batchScheduleSchema>
