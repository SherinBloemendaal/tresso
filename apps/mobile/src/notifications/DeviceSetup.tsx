import { useEffect } from 'react'
import { AppState, Platform } from 'react-native'
import { useAuth } from '@/auth/AuthProvider'
import { useNotices } from '@/components/Notices'
import { createReminderChannels } from './channels'
import { startTimeZoneSync, storedSentZone } from './timeZone'

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
    const report = (error: unknown) => notices.notify({ text: `Reminders may come at the wrong time: your time zone could not be saved (${error instanceof Error ? error.message : String(error)}).`, tone: 'error' })
    let stop: (() => void) | null = null
    let current = true
    // The zone this phone sent is remembered per user, so a restart (or another user here) decides right.
    auth.session
      .me()
      .then((me) => {
        if (current) {
          stop = startTimeZoneSync((path, init) => auth.session.api(path, init), storedSentZone(me.id), AppState, report)
        }
      })
      .catch(report)
    return () => {
      current = false
      stop?.()
    }
  }, [signedIn, auth.session, notices])

  return null
}
