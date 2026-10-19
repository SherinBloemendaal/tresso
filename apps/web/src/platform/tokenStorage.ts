import type { TokenSet } from '@tresso/core'
import { clearKeychainTokens, isDesktop, loadKeychainTokens, saveKeychainTokens } from '@/platform/desktop'

const TOKENS_KEY = 'auth.tokens'

/** Where the signed-in user's tokens are kept between page loads or app launches. */
export interface TokenStorage {
  load(): Promise<TokenSet | null>
  save(tokens: TokenSet | null): Promise<void>
}

/**
 * Tokens read without waiting, so the web client can render signed in on the first frame. The desktop app has to
 * ask the keychain first, see {@link TokenStorage.load}.
 */
export function initialTokens(): TokenSet | null {
  if (isDesktop) {
    return null
  }
  const raw = sessionStorage.getItem(TOKENS_KEY)
  return raw === null ? null : (JSON.parse(raw) as TokenSet)
}

/** The web client: sessionStorage survives a reload but not closing the tab. */
const sessionTokens: TokenStorage = {
  async load() {
    return initialTokens()
  },
  async save(tokens) {
    if (tokens === null) {
      sessionStorage.removeItem(TOKENS_KEY)
      return
    }
    sessionStorage.setItem(TOKENS_KEY, JSON.stringify(tokens))
  },
}

/** The desktop app: the OS keychain, so a sign-in lasts across launches without tokens on disk in plain text. */
const keychainTokens: TokenStorage = {
  async load() {
    const raw = await loadKeychainTokens()
    return raw === null ? null : (JSON.parse(raw) as TokenSet)
  },
  async save(tokens) {
    if (tokens === null) {
      await clearKeychainTokens()
      return
    }
    await saveKeychainTokens(JSON.stringify(tokens))
  },
}

export const tokenStorage: TokenStorage = isDesktop ? keychainTokens : sessionTokens
