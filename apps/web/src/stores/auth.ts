import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import {
  exchangeCode,
  parseCallback,
  refreshTokens,
  startAuthorization,
  type Me,
  type OAuthClientConfig,
  type TokenSet,
} from '@tresso/core'
import { ApiError } from '@/api/errors'
import { config } from '@/config'

const TOKENS_KEY = 'auth.tokens'
const PENDING_KEY = 'auth.pending'
/** Refresh slightly before expiry so a request never leaves with a token that dies in flight. */
const EXPIRY_MARGIN_MS = 30_000

const oauthConfig: OAuthClientConfig = config

// Tokens live in sessionStorage: they survive a reload but not closing the tab. The desktop and mobile apps keep
// them in the OS keychain instead.
function readTokens(): TokenSet | null {
  const raw = sessionStorage.getItem(TOKENS_KEY)
  return raw === null ? null : (JSON.parse(raw) as TokenSet)
}

export const useAuthStore = defineStore('auth', () => {
  const tokens = ref<TokenSet | null>(readTokens())
  const me = ref<Me | null>(null)
  const isAuthenticated = computed(() => tokens.value !== null)

  function store(next: TokenSet | null): void {
    tokens.value = next
    if (next === null) {
      sessionStorage.removeItem(TOKENS_KEY)
      return
    }
    sessionStorage.setItem(TOKENS_KEY, JSON.stringify(next))
  }

  async function signIn(): Promise<void> {
    const pending = await startAuthorization(oauthConfig)
    sessionStorage.setItem(PENDING_KEY, JSON.stringify({ state: pending.state, codeVerifier: pending.codeVerifier }))
    window.location.assign(pending.url)
  }

  async function completeSignIn(callbackUrl: string): Promise<void> {
    const raw = sessionStorage.getItem(PENDING_KEY)
    sessionStorage.removeItem(PENDING_KEY)
    if (raw === null) {
      throw new Error('No sign-in was started in this tab.')
    }
    const pending = JSON.parse(raw) as { state: string; codeVerifier: string }
    const code = parseCallback(callbackUrl, pending.state)
    store(await exchangeCode(oauthConfig, code, pending.codeVerifier))
  }

  function signOut(): void {
    store(null)
    me.value = null
  }

  async function accessToken(): Promise<string> {
    const current = tokens.value
    if (current === null) {
      throw new Error('Not signed in.')
    }
    if (current.expiresAt - EXPIRY_MARGIN_MS > Date.now()) {
      return current.accessToken
    }
    if (current.refreshToken === null) {
      signOut()
      throw new Error('Session expired.')
    }
    try {
      store(await refreshTokens(oauthConfig, current.refreshToken))
    } catch (error) {
      signOut()
      throw error
    }
    return tokens.value!.accessToken
  }

  async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
    const headers = new Headers(init.headers)
    headers.set('Authorization', `Bearer ${await accessToken()}`)
    headers.set('Accept', 'application/json')

    const response = await fetch(`${config.apiUrl}${path}`, { ...init, headers })
    if (response.status === 401) {
      signOut()
      throw new ApiError(401, 'Your session has expired. Sign in again.')
    }
    if (!response.ok) {
      throw await ApiError.fromResponse(path, response)
    }
    if (response.status === 204) {
      return undefined as T
    }
    return (await response.json()) as T
  }

  /** POST, PATCH (as JSON merge patch) or DELETE. */
  function send<T>(method: 'POST' | 'PATCH' | 'DELETE', path: string, body?: unknown): Promise<T> {
    const init: RequestInit = { method }
    if (body !== undefined) {
      init.headers = { 'Content-Type': method === 'PATCH' ? 'application/merge-patch+json' : 'application/json' }
      init.body = JSON.stringify(body)
    }
    return api<T>(path, init)
  }

  async function loadMe(): Promise<void> {
    me.value = await api<Me>('/api/v1/me')
  }

  return { tokens, me, isAuthenticated, signIn, completeSignIn, signOut, loadMe, api, send }
})
