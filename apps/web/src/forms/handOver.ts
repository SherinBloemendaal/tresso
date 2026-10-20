import { z } from 'zod'
import { dueOn, isPlausibleAddress, MAX_DUE_DAYS, MAX_NOTE } from '@tresso/core'

const person = z.object({
  address: z.string().refine(isPlausibleAddress, (value) => ({ message: `${value} is not a valid address.` })),
  name: z.string().nullable(),
})

/** A picked day (yyyy-mm-dd, the date input's value) as the moment it is due on that day. */
export function pickedDue(day: string): Date | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(day)
  if (match === null) {
    return null
  }
  return dueOn(new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])))
}

/** What the hand-over form checks before the forward goes; the API checks the due date and the note again. */
export function handOverSchema(now: () => Date = () => new Date()) {
  return z
    .object({
      delegate: z.array(person).min(1, 'Choose who to hand it over to.').max(1, 'Hand it over to one person.'),
      note: z.string().max(MAX_NOTE, `Use at most ${MAX_NOTE} characters.`),
      due: z.enum(['today', 'tomorrow', 'friday', 'nextWeek', 'custom']),
      dueDay: z.string(),
    })
    .superRefine((values, context) => {
      if (values.due !== 'custom') {
        return
      }
      const at = pickedDue(values.dueDay)
      if (at === null) {
        context.addIssue({ code: z.ZodIssueCode.custom, path: ['dueDay'], message: 'Pick the day it is due.' })
        return
      }
      if (at.getTime() <= now().getTime()) {
        context.addIssue({ code: z.ZodIssueCode.custom, path: ['dueDay'], message: 'Pick a day that is still ahead.' })
      }
      if (at.getTime() > now().getTime() + MAX_DUE_DAYS * 86_400_000) {
        context.addIssue({ code: z.ZodIssueCode.custom, path: ['dueDay'], message: 'Pick a day within a year.' })
      }
    })
}

export type HandOverValues = z.infer<ReturnType<typeof handOverSchema>>
