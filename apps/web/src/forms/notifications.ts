import { z } from 'zod'
import type { NotificationChannel, NotificationSettings } from '@tresso/core'

/** Where mail notifications go unless the user chose (NotificationKind::Mail in the API). */
export const DEFAULT_MAIL_CHANNELS: NotificationChannel[] = ['phone', 'desktop']

export const mailNotificationSchema = z.object({
  channels: z.array(z.enum(['phone', 'desktop', 'email'])),
  holdInMeetings: z.boolean(),
})

export type MailNotificationValues = z.infer<typeof mailNotificationSchema>

/** The form's values for the user's settings: their choice for mail, else the default. */
export function mailNotificationValues(settings: NotificationSettings): MailNotificationValues {
  return { channels: [...(settings.channelsByKind.mail ?? DEFAULT_MAIL_CHANNELS)], holdInMeetings: settings.holdMailInMeetings }
}

/** The PATCH for /api/v1/notification-settings; other kinds keep their choices (channelsByKind merges per kind). */
export function mailNotificationPatch(values: MailNotificationValues): Pick<NotificationSettings, 'holdMailInMeetings'> & { channelsByKind: { mail: NotificationChannel[] } } {
  return { channelsByKind: { mail: values.channels }, holdMailInMeetings: values.holdInMeetings }
}
