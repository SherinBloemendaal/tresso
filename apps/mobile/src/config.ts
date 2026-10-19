function required(name: string, value: string | undefined): string {
  if (value === undefined || value === '') {
    throw new Error(`Missing environment variable ${name}`)
  }
  return value
}

// Each variable is read by its full name: Expo only inlines `process.env.EXPO_PUBLIC_*` accessed that way.
export const config = {
  apiUrl: required('EXPO_PUBLIC_API_URL', process.env.EXPO_PUBLIC_API_URL),
  clientId: required('EXPO_PUBLIC_OAUTH_CLIENT_ID', process.env.EXPO_PUBLIC_OAUTH_CLIENT_ID),
  // The authorization server only accepts the registered `tresso://auth/callback`, so sign-in needs a development
  // build: Expo Go would ask for an `exp://` redirect.
  redirectUri: required('EXPO_PUBLIC_NATIVE_REDIRECT_URI', process.env.EXPO_PUBLIC_NATIVE_REDIRECT_URI),
}
