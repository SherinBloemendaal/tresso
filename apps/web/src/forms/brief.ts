import { z } from 'zod'

/** Mirrors the API's checks (App\ApiResource\Input\Brief\DailyBriefSettingsInput); the server has the last word. */
export const briefSettingsSchema = z.object({
  enabled: z.boolean(),
  deliverAt: z.string().min(1, 'Pick a time of day.').regex(/^([01]\d|2[0-3]):[0-5]\d$/, 'Pick a time of day.'),
  channels: z.array(z.enum(['phone', 'desktop', 'email'])),
})

export type BriefSettingsValues = z.infer<typeof briefSettingsSchema>
