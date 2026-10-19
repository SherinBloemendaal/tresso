import { describe, expect, it } from 'vitest'
import { requiredPaths } from '../required'
import { smartInboxSchema } from '../smartInboxes'

const valid = { name: 'Invoices', instructions: '', includeSenders: ['@supplier.example'], excludeSenders: ['news@supplier.example'], verdict: 'do' as const }

describe('smartInboxSchema', () => {
  it('accepts a complete smart inbox', () => {
    expect(smartInboxSchema.safeParse(valid).success).toBe(true)
  })

  it.each([
    ['a blank name', { name: '  ' }, 'name'],
    ['a bare domain', { includeSenders: ['supplier.example'] }, 'includeSenders.0'],
    ['the same sender on both lists', { excludeSenders: ['@SUPPLIER.example'] }, 'excludeSenders'],
    ['an unknown verdict', { verdict: 'later' }, 'verdict'],
  ])('refuses %s', (_case, change, path) => {
    const result = smartInboxSchema.safeParse({ ...valid, ...change })

    expect(result.success).toBe(false)
    expect(result.error?.issues.map((issue) => issue.path.join('.'))).toContain(path)
  })

  it('marks the name and the verdict as required', () => {
    expect([...requiredPaths(smartInboxSchema)].sort()).toEqual(['name', 'verdict'])
  })
})
