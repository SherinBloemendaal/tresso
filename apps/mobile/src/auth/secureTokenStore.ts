import * as SecureStore from 'expo-secure-store'
import type { TokenSet } from '@tresso/core'
import type { TokenStore } from '@/auth/session'

// Three entries rather than one JSON value: some iOS releases refuse values above about 2 KB, and an access token
// alone comes close to that.
const ACCESS_TOKEN = 'auth.accessToken'
const REFRESH_TOKEN = 'auth.refreshToken'
const EXPIRES_AT = 'auth.expiresAt'
/**
 * Who was signed in last, for opening their mail before the server answers (see MailProvider). It lives with the
 * tokens and goes with them on sign-out, so it never outlasts the sign-in it belongs to.
 */
export const LAST_USER = 'auth.lastUserId'

/** The OS secure store: the iOS Keychain, or values encrypted with the Android Keystore. */
export const secureTokenStore: TokenStore = {
  async load() {
    const [accessToken, refreshToken, expiresAt] = await Promise.all([
      SecureStore.getItemAsync(ACCESS_TOKEN),
      SecureStore.getItemAsync(REFRESH_TOKEN),
      SecureStore.getItemAsync(EXPIRES_AT),
    ])
    // A save that failed halfway leaves an incomplete set; that is no sign-in.
    if (accessToken === null || expiresAt === null || !Number.isFinite(Number(expiresAt))) {
      return null
    }
    return { accessToken, refreshToken, expiresAt: Number(expiresAt) } satisfies TokenSet
  },
  async save(tokens) {
    if (tokens === null) {
      await Promise.all([ACCESS_TOKEN, REFRESH_TOKEN, EXPIRES_AT, LAST_USER].map((key) => SecureStore.deleteItemAsync(key)))
      return
    }
    // The expiry goes last, so an interrupted save never pairs a new expiry with an old token.
    await SecureStore.deleteItemAsync(EXPIRES_AT)
    await SecureStore.setItemAsync(ACCESS_TOKEN, tokens.accessToken)
    if (tokens.refreshToken === null) {
      await SecureStore.deleteItemAsync(REFRESH_TOKEN)
    } else {
      await SecureStore.setItemAsync(REFRESH_TOKEN, tokens.refreshToken)
    }
    await SecureStore.setItemAsync(EXPIRES_AT, String(tokens.expiresAt))
  },
}
