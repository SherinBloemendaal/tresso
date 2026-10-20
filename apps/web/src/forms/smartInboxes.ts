import { z } from 'zod'
import { isSenderPattern, normalizeSenderPattern } from '@tresso/core'

/** Mirrors the API's limits (App\Entity\Category). */
export const SMART_INBOX_NAME_MAX = 80
export const SMART_INBOX_INSTRUCTIONS_MAX = 2000
export const SMART_INBOX_SENDERS_MAX = 500

const senders = z
  .array(z.string().refine(isSenderPattern, 'Use an address (anna@example.com) or a domain (@example.com).'))
  .max(SMART_INBOX_SENDERS_MAX, `Use at most ${SMART_INBOX_SENDERS_MAX} entries.`)

export const smartInboxSchema = z
  .object({
    name: z.string().trim().min(1, 'Give the smart inbox a name.').max(SMART_INBOX_NAME_MAX, `Use at most ${SMART_INBOX_NAME_MAX} characters.`),
    instructions: z.string().max(SMART_INBOX_INSTRUCTIONS_MAX, `Use at most ${SMART_INBOX_INSTRUCTIONS_MAX} characters.`),
    includeSenders: senders,
    excludeSenders: senders,
    verdict: z.enum(['do', 'delegate', 'know', 'none']),
    // A switch always has a value: not a field to fill in.
    autoCreateTodo: z.boolean().default(false),
  })
  .superRefine((values, context) => {
    if (values.autoCreateTodo && values.verdict !== 'do') {
      context.addIssue({ code: z.ZodIssueCode.custom, path: ['autoCreateTodo'], message: 'Only smart inboxes with the Do verdict can create todos.' })
    }
    const included = new Set(values.includeSenders.map(normalizeSenderPattern))
    const both = values.excludeSenders.find((entry) => included.has(normalizeSenderPattern(entry)))
    if (both !== undefined) {
      context.addIssue({ code: z.ZodIssueCode.custom, path: ['excludeSenders'], message: `${both} cannot be on both lists.` })
    }
  })

export type SmartInboxValues = z.infer<typeof smartInboxSchema>
