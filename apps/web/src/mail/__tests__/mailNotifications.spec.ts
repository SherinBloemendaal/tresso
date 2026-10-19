import { describe, expect, it } from 'vitest'
import type { Arrival } from '../db'
import { FRESHNESS_MS, freshArrivals, mailNotifications } from '../mailNotifications'

function arrival(id: string, extra: Partial<Arrival> = {}): Arrival {
  return {
    messageId: id,
    threadId: `t-${id}`,
    mailAccountId: 'work',
    from: { address: `${id}@example.test`, name: `Sender ${id}` },
    subject: `Subject ${id}`,
    snippet: `Snippet ${id}`,
    receivedAt: '2026-10-19T08:00:00Z',
    verdict: 'do',
    ...extra,
  }
}

describe('mailNotifications', () => {
  it('shows each message with its sender, subject and snippet', () => {
    expect(mailNotifications([arrival('a'), arrival('b', { verdict: 'delegate', from: { address: 'b@example.test', name: null }, snippet: '' })])).toEqual([
      { title: 'Sender a', body: 'Subject a\nSnippet a' },
      { title: 'b@example.test', body: 'To hand over: Subject b' },
    ])
  })

  it('names a message without a subject', () => {
    expect(mailNotifications([arrival('a', { subject: '', snippet: '' })])).toEqual([{ title: 'Sender a', body: '(no subject)' }])
  })

  it('sums up when much arrives at once', () => {
    expect(mailNotifications(['a', 'b', 'c', 'd'].map((id) => arrival(id)))).toEqual([
      { title: '4 new messages need you', body: 'From Sender a, Sender b, Sender c and 1 more' },
    ])
    expect(mailNotifications(['a', 'a', 'b', 'b'].map((id, i) => arrival(id, { messageId: `${id}${i}` })))).toEqual([
      { title: '4 new messages need you', body: 'From Sender a, Sender b' },
    ])
  })
})

describe('freshArrivals', () => {
  it('leaves out old mail that reaches the device late', () => {
    const since = Date.parse('2026-10-19T08:00:00Z')
    const recent = arrival('recent', { receivedAt: new Date(since - FRESHNESS_MS + 1000).toISOString() })
    const old = arrival('old', { receivedAt: new Date(since - FRESHNESS_MS - 1000).toISOString() })
    const later = arrival('later', { receivedAt: '2026-10-19T09:00:00+01:00' })

    expect(freshArrivals([recent, old, later], since)).toEqual([recent, later])
  })
})
