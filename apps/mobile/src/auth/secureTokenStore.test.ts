import { beforeEach, describe, expect, it, vi } from 'vitest'

const items = vi.hoisted(() => new Map<string, string>())

vi.mock('expo-secure-store', () => ({
  getItemAsync: async (key: string) => items.get(key) ?? null,
  setItemAsync: async (key: string, value: string) => {
    items.set(key, value)
  },
  deleteItemAsync: async (key: string) => {
    items.delete(key)
  },
}))

const { secureTokenStore } = await import('./secureTokenStore')

describe('secureTokenStore', () => {
  beforeEach(() => items.clear())

  it('keeps each token in its own entry and reads them back', async () => {
    await secureTokenStore.save({ accessToken: 'a'.repeat(1800), refreshToken: 'r'.repeat(1500), expiresAt: 1_800_000_000_000 })

    expect([...items.keys()].sort()).toEqual(['auth.accessToken', 'auth.expiresAt', 'auth.refreshToken'])
    expect(await secureTokenStore.load()).toEqual({ accessToken: 'a'.repeat(1800), refreshToken: 'r'.repeat(1500), expiresAt: 1_800_000_000_000 })
  })

  it('keeps a sign-in without a refresh token', async () => {
    await secureTokenStore.save({ accessToken: 'access', refreshToken: 'stale', expiresAt: 1 })
    await secureTokenStore.save({ accessToken: 'access', refreshToken: null, expiresAt: 2 })

    expect(await secureTokenStore.load()).toEqual({ accessToken: 'access', refreshToken: null, expiresAt: 2 })
  })

  it('treats an incomplete set as signed out', async () => {
    items.set('auth.accessToken', 'access')
    items.set('auth.refreshToken', 'refresh')

    expect(await secureTokenStore.load()).toBeNull()
  })

  it('forgets everything on sign-out', async () => {
    await secureTokenStore.save({ accessToken: 'access', refreshToken: 'refresh', expiresAt: 1 })
    await secureTokenStore.save(null)

    expect(items.size).toBe(0)
    expect(await secureTokenStore.load()).toBeNull()
  })
})
