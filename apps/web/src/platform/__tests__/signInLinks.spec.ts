import { describe, expect, it } from 'vitest'
import { signInRoute } from '../signInLinks'

const REDIRECT = 'tresso://auth/callback'

describe('signInRoute', () => {
  it('routes a sign-in result to the callback screen with its parameters', () => {
    expect(signInRoute(`${REDIRECT}?code=abc&state=xyz`, REDIRECT)).toEqual({ name: 'auth-callback', query: { code: 'abc', state: 'xyz' } })
  })

  it('keeps an error the authorization server sent back', () => {
    expect(signInRoute(`${REDIRECT}?error=access_denied&error_description=Denied%20by%20you&state=xyz`, REDIRECT)).toEqual({
      name: 'auth-callback',
      query: { error: 'access_denied', error_description: 'Denied by you', state: 'xyz' },
    })
  })

  it('ignores links that are not this app\'s redirect URI', () => {
    expect(signInRoute('tresso://compose?to=kim@example.com', REDIRECT)).toBeNull()
    expect(signInRoute('tresso://auth/callback-other?code=abc', REDIRECT)).toBeNull()
    expect(signInRoute('https://evil.example/auth/callback?code=abc', REDIRECT)).toBeNull()
  })
})
