function required(name: string, value: string | undefined): string {
  if (value === undefined || value === '') {
    throw new Error(`Missing environment variable ${name}`)
  }
  return value
}

export const config = {
  apiUrl: required('VITE_API_URL', import.meta.env.VITE_API_URL),
  clientId: required('VITE_OAUTH_CLIENT_ID', import.meta.env.VITE_OAUTH_CLIENT_ID),
  redirectUri: `${window.location.origin}/auth/callback`,
}
