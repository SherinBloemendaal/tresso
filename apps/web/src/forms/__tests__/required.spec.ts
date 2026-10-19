import { describe, expect, it } from 'vitest'
import { accountSchema, addressSchema, connectSchema } from '../accounts'
import { composeSchema } from '../compose'
import { requiredPaths } from '../required'

describe('requiredPaths', () => {
  it('reads required fields, nested ones included, from the schemas', () => {
    expect([...requiredPaths(addressSchema)]).toEqual(['emailAddress', 'displayName'])
    expect([...requiredPaths(connectSchema)]).toEqual(['username', 'password', 'imap.host', 'imap.port', 'imap.security', 'smtp.host', 'smtp.port', 'smtp.security'])
  })

  it('leaves out what may stay empty', () => {
    expect(requiredPaths(accountSchema).has('password')).toBe(false)
    expect(requiredPaths(composeSchema).has('subject')).toBe(false)
    expect(requiredPaths(composeSchema).has('mailAccountId')).toBe(true)
  })
})
