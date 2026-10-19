import { isDesktop } from '@/platform/desktop'

function required(name: string, value: string | undefined): string {
  if (value === undefined || value === '') {
    throw new Error(`Missing environment variable ${name}`)
  }
  return value
}

// The desktop app is its own OAuth client: it cannot receive a redirect to a web page, so the authorization server
// sends the browser to `tresso://auth/callback` and the shell hands that link to the app.
export const config = {
  apiUrl: required('VITE_API_URL', import.meta.env.VITE_API_URL),
  clientId: isDesktop
    ? required('VITE_DESKTOP_OAUTH_CLIENT_ID', import.meta.env.VITE_DESKTOP_OAUTH_CLIENT_ID)
    : required('VITE_OAUTH_CLIENT_ID', import.meta.env.VITE_OAUTH_CLIENT_ID),
  redirectUri: isDesktop
    ? required('VITE_NATIVE_REDIRECT_URI', import.meta.env.VITE_NATIVE_REDIRECT_URI)
    : `${window.location.origin}/auth/callback`,
}
