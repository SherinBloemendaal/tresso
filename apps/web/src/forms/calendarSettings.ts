import { z } from 'zod'

/** Mirrors the API's checks (App\ApiResource\Input\Calendar\UpdateSchedulingPreferences); the server has the last word. */
export const calendarSettingsSchema = z.object({
  planTodos: z.boolean(),
})

export type CalendarSettingsValues = z.infer<typeof calendarSettingsSchema>
