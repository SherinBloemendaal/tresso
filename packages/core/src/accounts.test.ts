import { describe, expect, it } from 'vitest'
import { defaultPort } from './accounts'

describe('defaultPort', () => {
  it('returns the registered ports', () => {
    expect(defaultPort('imap', 'tls')).toBe(993)
    expect(defaultPort('imap', 'starttls')).toBe(143)
    expect(defaultPort('smtp', 'tls')).toBe(465)
    expect(defaultPort('smtp', 'starttls')).toBe(587)
  })
})
