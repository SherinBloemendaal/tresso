import { describe, expect, it } from 'vitest'
import { absentConversation } from './openedConversation'

describe('absentConversation', () => {
  it('is loading until a pull completes after the conversation opened', () => {
    expect(absentConversation(3, 3, 'syncing')).toBe('loading')
    expect(absentConversation(3, 3, 'idle')).toBe('loading')
  })

  it('could not be loaded when the pull fails, as offline', () => {
    expect(absentConversation(3, 3, 'error')).toBe('unreachable')
  })

  it('is gone once a pull after opening did not bring it', () => {
    expect(absentConversation(4, 3, 'idle')).toBe('missing')
    expect(absentConversation(4, 3, 'error')).toBe('missing')
  })
})
