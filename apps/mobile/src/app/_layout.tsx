import { Stack } from 'expo-router'
import { StatusBar } from 'expo-status-bar'
import * as WebBrowser from 'expo-web-browser'
import { AuthProvider } from '@/auth/AuthProvider'
import { MailProvider } from '@/mail/MailProvider'

// Closes the sign-in popup when the app runs on the web; a no-op on iOS and Android.
WebBrowser.maybeCompleteAuthSession()

export default function RootLayout() {
  return (
    <AuthProvider>
      <MailProvider>
        <Stack screenOptions={{ headerShown: false }} />
        <StatusBar style="auto" />
      </MailProvider>
    </AuthProvider>
  )
}
