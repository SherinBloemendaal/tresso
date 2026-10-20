import { useEffect, useRef } from 'react'
import { AppState, Platform } from 'react-native'
import { router } from 'expo-router'
import * as Notifications from 'expo-notifications'
import { useAuth } from '@/auth/AuthProvider'
import { useNotices } from '@/components/Notices'
import { createNotificationChannels } from './channels'
import { expoPush, secureDeviceId } from './expoPush'
import { foregroundBehavior, startPushRegistration, tappedThread } from './push'
import { startTimeZoneSync, storedSentZone } from './timeZone'

// How a push shows while the app is open; set before any push can arrive.
Notifications.setNotificationHandler({ handleNotification: async (notification) => foregroundBehavior(notification.request.content.data) })

/**
 * What the phone sets up for notifications: the Android channels the API's pushes name, at launch, and, once signed
 * in, this phone as a push device and the time zone reminders are planned in. A tapped mail notification opens its
 * conversation.
 */
export function DeviceSetup() {
  const auth = useAuth()
  const notices = useNotices()
  const signedIn = auth.isAuthenticated
  const tapped = Notifications.useLastNotificationResponse()
  const opened = useRef<string | null>(null)

  useEffect(() => {
    createNotificationChannels(Platform.OS).catch((error: unknown) => {
      notices.notify({ text: `Notifications may come without their sound settings: ${error instanceof Error ? error.message : String(error)}`, tone: 'error' })
    })
  }, [notices])

  useEffect(() => {
    if (!signedIn) {
      return
    }
    return startPushRegistration(
      (path, init) => auth.session.api(path, init),
      expoPush,
      secureDeviceId,
      (error) => notices.notify({ text: `This phone cannot get notifications: ${error instanceof Error ? error.message : String(error)}.`, tone: 'error' }),
    )
  }, [signedIn, auth.session, notices])

  useEffect(() => {
    if (!signedIn || tapped == null || tapped.actionIdentifier !== Notifications.DEFAULT_ACTION_IDENTIFIER) {
      return
    }
    const key = tapped.notification.request.identifier
    const threadId = tappedThread(tapped.notification.request.content.data)
    if (threadId === null || opened.current === key) {
      return
    }
    opened.current = key
    router.push({ pathname: '/thread/[id]', params: { id: threadId } })
  }, [signedIn, tapped])

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
