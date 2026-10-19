import { isPermissionGranted, requestPermission, sendNotification } from '@tauri-apps/plugin-notification'
import { isDesktop } from '@/platform/desktop'
import type { MailNotification } from '@/mail/mailNotifications'

/** Shows notifications outside the app. */
export interface Notifier {
  show(notifications: MailNotification[]): Promise<void>
}

/** The desktop app: native notifications, asking the operating system for permission the first time. */
const desktopNotifier: Notifier = {
  async show(notifications) {
    if (notifications.length === 0) {
      return
    }
    if (!(await isPermissionGranted()) && (await requestPermission()) !== 'granted') {
      return
    }
    for (const notification of notifications) {
      sendNotification(notification)
    }
  },
}

/**
 * The browser build shows none: notifications from an open tab are no substitute for Web Push sent by the server,
 * which the architecture plans for the browser.
 */
export const notifier: Notifier | null = isDesktop ? desktopNotifier : null
