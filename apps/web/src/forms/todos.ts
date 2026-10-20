import { z } from 'zod'
import { parseQuickAdd, TODO_DURATION_MAX_MINUTES, TODO_NOTES_MAX_LENGTH, TODO_TITLE_MAX_LENGTH } from '@tresso/core'

/** One line: what to do, and maybe when ("Call Anna friday"). */
export const quickAddSchema = z.object({
  text: z
    .string()
    .trim()
    .min(1, 'Type what to do.')
    .max(TODO_TITLE_MAX_LENGTH, `Use at most ${TODO_TITLE_MAX_LENGTH} characters.`)
    .refine((text) => parseQuickAdd(text, new Date()).title !== '', 'Say what to do, not only when.'),
})

export type QuickAddValues = z.infer<typeof quickAddSchema>

/** A todo as the detail form edits it: the date, time and duration inputs give text, '' when empty. */
export const todoSchema = z
  .object({
    title: z.string().trim().min(1, 'Give the todo a title.').max(TODO_TITLE_MAX_LENGTH, `Use at most ${TODO_TITLE_MAX_LENGTH} characters.`),
    notes: z.string().max(TODO_NOTES_MAX_LENGTH, `Use at most ${TODO_NOTES_MAX_LENGTH} characters.`),
    dueDate: z.union([z.literal(''), z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Use a date.')]),
    dueTime: z.union([z.literal(''), z.string().regex(/^\d{2}:\d{2}$/, 'Use a time.')]),
    durationMinutes: z
      .string()
      .trim()
      .refine((value) => value === '' || (/^\d+$/.test(value) && Number(value) >= 1 && Number(value) <= TODO_DURATION_MAX_MINUTES), `Use 1 to ${TODO_DURATION_MAX_MINUTES} minutes.`),
  })
  .superRefine((values, context) => {
    if (values.dueTime !== '' && values.dueDate === '') {
      context.addIssue({ code: z.ZodIssueCode.custom, path: ['dueTime'], message: 'A time needs a date.' })
    }
  })

export type TodoValues = z.infer<typeof todoSchema>
