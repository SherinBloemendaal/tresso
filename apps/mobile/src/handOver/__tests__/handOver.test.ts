import { describe, expect, it } from 'vitest'
import type { SyncedHandOver, SyncedOutgoingMessage } from '@tresso/core'
import { handOverProblem } from '../form'
import { statusText } from '../format'

const lisa = { address: 'lisa@company.example', name: 'Lisa Bakker' }

function handOver(extra: Partial<SyncedHandOver> = {}): SyncedHandOver {
  return {
    id: 'h1',
    mailAccountId: 'work',
    messageId: 'm1',
    threadId: 't1',
    subject: 'Invoice question',
    delegate: lisa,
    note: '',
    dueAt: '2026-10-23T15:00:00Z',
    state: 'open',
    forwardId: 'f1',
    overdueAt: null,
    notifiedAt: null,
    notifiedChannels: [],
    notifiedSilently: false,
    nudgedAt: null,
    lastNudgeId: null,
    replyMessageId: null,
    createdAt: '2026-10-20T08:00:00Z',
    closedAt: null,
    updatedAt: '2026-10-20T08:00:00Z',
    ...extra,
  }
}

describe('handOverProblem', () => {
  it('asks for exactly one valid person and a note within the limit', () => {
    expect(handOverProblem([], '')).toBe('Choose who to hand it over to.')
    expect(handOverProblem([lisa, { address: 'tom@company.example', name: null }], '')).toBe('Hand it over to one person.')
    expect(handOverProblem([{ address: 'not an address', name: null }], '')).toBe('not an address is not a valid address.')
    expect(handOverProblem([lisa], 'x'.repeat(10_001))).toBe('Use at most 10000 characters in the note.')
    expect(handOverProblem([lisa], 'Can you check?')).toBeNull()
  })
})

describe('statusText', () => {
  const now = new Date('2026-10-22T09:00:00Z')
  const nudge = (status: SyncedOutgoingMessage['status']): SyncedOutgoingMessage => ({ id: 'n1', mailAccountId: 'work', status, subject: '', recipients: { to: [], cc: [], bcc: [] }, sendAt: '', sentAt: null, lastError: null, inReplyToMessageId: null })

  it('says how a hand-over stands', () => {
    expect(statusText(handOver(), null, now)).toBe('Waiting')
    expect(statusText(handOver(), null, new Date('2026-10-24T09:00:00Z'))).toBe('Overdue')
    expect(statusText(handOver({ state: 'replied' }), null, now)).toBe('Replied')
    expect(statusText(handOver({ state: 'done' }), null, now)).toBe('Done')
  })

  it('counts a nudge that was not cancelled', () => {
    const nudged = handOver({ nudgedAt: '2026-10-21T09:00:00Z', lastNudgeId: 'n1' })

    expect(statusText(nudged, nudge('sent'), now)).toMatch(/^Nudged /)
    expect(statusText(nudged, nudge('cancelled'), now)).toBe('Waiting')
  })
})
