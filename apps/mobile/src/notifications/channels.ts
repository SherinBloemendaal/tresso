import { AndroidImportance, setNotificationChannelAsync } from 'expo-notifications'

/**
 * The Android channels the API names in its pushes (PushMessage.php: `reminders`, and `reminders_silent` for
 * reminders delivered in quiet hours or focus). A push naming a channel the phone does not have is shown in Android's
 * fallback channel, so both exist before any push arrives. iOS has no channels.
 */
export async function createReminderChannels(platform: string): Promise<void> {
  if (platform !== 'android') {
    return
  }
  await setNotificationChannelAsync('reminders', {
    name: 'Reminders',
    importance: AndroidImportance.DEFAULT,
    sound: 'default',
  })
  await setNotificationChannelAsync('reminders_silent', {
    name: 'Quiet reminders',
    description: 'Reminders that come during quiet hours or focus: in the list, without sound.',
    importance: AndroidImportance.LOW,
    sound: null,
    enableVibrate: false,
  })
}
