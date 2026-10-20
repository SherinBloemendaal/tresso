import Constants from 'expo-constants'
import * as Notifications from 'expo-notifications'
import * as SecureStore from 'expo-secure-store'
import { Platform } from 'react-native'
import type { DeviceIdStore, PushPlatform } from './push'

const DEVICE_ID_KEY = 'pushDevice'

/** The EAS project the Expo push token belongs to; `eas init` writes it to app.json (extra.eas.projectId). */
function easProjectId(): string {
  const id: unknown = Constants.expoConfig?.extra?.eas?.projectId ?? Constants.easConfig?.projectId
  if (typeof id !== 'string' || id === '') {
    throw new Error('this build has no EAS project id (run eas init, which adds extra.eas.projectId to app.json)')
  }
  return id
}

/** Push through Expo's notifications module. */
export const expoPush: PushPlatform = {
  async permitted() {
    const current = await Notifications.getPermissionsAsync()
    if (current.granted) {
      return true
    }
    if (!current.canAskAgain) {
      return false
    }
    return (await Notifications.requestPermissionsAsync()).granted
  },
  async token() {
    return (await Notifications.getExpoPushTokenAsync({ projectId: easProjectId() })).data
  },
  onTokenChange(listener) {
    const subscription = Notifications.addPushTokenListener(() => listener())
    return () => subscription.remove()
  },
  name: Constants.deviceName ?? (Platform.OS === 'ios' ? 'iPhone' : 'Android phone'),
}

/** The registered device's id, in the secure store. */
export const secureDeviceId: DeviceIdStore = {
  get: () => SecureStore.getItemAsync(DEVICE_ID_KEY),
  set: (id) => (id === null ? SecureStore.deleteItemAsync(DEVICE_ID_KEY) : SecureStore.setItemAsync(DEVICE_ID_KEY, id)),
}
