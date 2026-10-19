import { z } from 'zod'
import { isPlausibleAddress } from '@tresso/core'

const recipient = z.object({
  address: z.string().refine(isPlausibleAddress, (value) => ({ message: `${value} is not a valid address.` })),
  name: z.string().nullable(),
})

/** What the compose form checks before anything is sent; the API checks the same again. */
export const composeSchema = z
  .object({
    mailAccountId: z.string({ required_error: 'Choose the account to send from.', invalid_type_error: 'Choose the account to send from.' }).min(1, 'Choose the account to send from.'),
    to: z.array(recipient),
    cc: z.array(recipient),
    bcc: z.array(recipient),
    subject: z.string().max(998, 'The subject is too long.').refine((value) => !/[\r\n]/.test(value), 'The subject cannot contain line breaks.'),
    html: z.string().max(2_000_000, 'The message is too long.'),
    signatureId: z.string().nullable(),
  })
  .superRefine((values, context) => {
    if (values.to.length + values.cc.length + values.bcc.length === 0) {
      context.addIssue({ code: z.ZodIssueCode.custom, path: ['to'], message: 'Add at least one recipient.' })
    }
    if (values.to.length + values.cc.length + values.bcc.length > 100) {
      context.addIssue({ code: z.ZodIssueCode.custom, path: ['to'], message: 'A message can have at most 100 recipients.' })
    }
  })

export type ComposeValues = z.infer<typeof composeSchema>
