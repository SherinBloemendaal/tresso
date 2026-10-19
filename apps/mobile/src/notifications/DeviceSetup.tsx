import { useEffect } from 'react'
import { AppState, Platform } from 'react-native'
import { useAuth } from '@/auth/AuthProvider'
import { useNotices } from '@/components/Notices'
import { createReminderChannels } from './channels'
import { startTimeZoneSync } from './timeZone'

/**
 * What the phone sets up for reminders: the Android channels the API's pushes name, at launch, and, once signed in,
 * the time zone reminders are planned in.
 */
export function DeviceSetup() {
  const auth = useAuth()
  const notices = useNotices()
  const signedIn = auth.isAuthenticated

  useEffect(() => {
    createReminderChannels(Platform.OS).catch((error: unknown) => {
      notices.notify({ text: `Reminders may come without their sound settings: ${error instanceof Error ? error.message : String(error)}`, tone: 'error' })
    })
  }, [notices])

  useEffect(() => {
    if (!signedIn) {
      return
    }
    return startTimeZoneSync(
      (path, init) => auth.session.api(path, init),
      AppState,
      (error) => notices.notify({ text: `Reminders may come at the wrong time: your time zone could not be saved (${error instanceof Error ? error.message : String(error)}).`, tone: 'error' }),
    )
  }, [signedIn, auth.session, notices])

  return null
}
