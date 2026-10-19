import { describe, expect, it } from 'vitest'
import { connectErrorMessage, defaultPort, signInProvider } from './accounts'

describe('defaultPort', () => {
  it('returns the registered ports', () => {
    expect(defaultPort('imap', 'tls')).toBe(993)
    expect(defaultPort('imap', 'starttls')).toBe(143)
    expect(defaultPort('smtp', 'tls')).toBe(465)
    expect(defaultPort('smtp', 'starttls')).toBe(587)
  })
})

describe('signInProvider', () => {
  it('maps the mail provider to the identity provider', () => {
    expect(signInProvider({ provider: 'gmail' })).toBe('google')
    expect(signInProvider({ provider: 'microsoft' })).toBe('microsoft')
    expect(signInProvider({ provider: 'imap' })).toBeNull()
  })
})

describe('connectErrorMessage', () => {
  it('names the provider', () => {
    expect(connectErrorMessage('scopes_missing', 'google')).toContain('Sign in with Google again')
    expect(connectErrorMessage('denied', 'microsoft')).toContain('Microsoft account')
  })

  it('treats unknown codes as a general failure', () => {
    expect(connectErrorMessage('<script>', 'google')).toBe(connectErrorMessage('failed', 'google'))
  })
})
