import { Redirect } from 'expo-router'

/**
 * The sign-in redirect `tresso://auth/callback`. The authentication browser hands it to the waiting sign-in, but
 * Android also opens the app with it; there is nothing to show, so it goes back to the start screen.
 */
export default function AuthCallback() {
  return <Redirect href="/" />
}
