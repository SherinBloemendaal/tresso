import { describe, expect, it } from 'vitest'
import type { SyncedMailNotification } from '@tresso/core'
import { FRESHNESS_MS, forTheDesktop, mailNotifications } from '../mailNotifications'

function delivered(id: string, extra: Partial<SyncedMailNotification> = {}): SyncedMailNotification {
  return {
    id,
    messageId: `m-${id}`,
    threadId: `t-${id}`,
    title: `Sender ${id}`,
    body: `Subject ${id}`,
    deliveredAt: '2026-10-19T08:00:00Z',
    channels: ['phone', 'desktop'],
    silent: false,
    ...extra,
  }
}

describe('mailNotifications', () => {
  it('shows each one as the server wrote it, opening its conversation', () => {
    expect(mailNotifications([delivered('a'), delivered('b', { threadId: null })])).toEqual([
      { title: 'Sender a', body: 'Subject a', path: '/thread/t-a' },
      { title: 'Sender b', body: 'Subject b', path: null },
    ])
  })

  it('sums up when much arrives at once', () => {
    expect(mailNotifications(['a', 'b', 'c', 'd'].map((id) => delivered(id)))).toEqual([
      { title: '4 new messages need you', body: 'From Sender a, Sender b, Sender c and 1 more', path: null },
    ])
    expect(mailNotifications(['a', 'a', 'b', 'b'].map((id, i) => delivered(`${id}${i}`, { title: `Sender ${id}` })))).toEqual([
      { title: '4 new messages need you', body: 'From Sender a, Sender b', path: null },
    ])
  })
})

describe('forTheDesktop', () => {
  const since = Date.parse('2026-10-19T08:00:00Z')

  it('takes what was sent to the desktop and is recent', () => {
    const recent = delivered('recent', { deliveredAt: new Date(since - FRESHNESS_MS + 1000).toISOString() })
    const old = delivered('old', { deliveredAt: new Date(since - FRESHNESS_MS - 1000).toISOString() })
    const later = delivered('later', { deliveredAt: '2026-10-19T09:00:00+01:00' })
    const phoneOnly = delivered('phone', { channels: ['phone'] })

    expect(forTheDesktop([recent, old, later, phoneOnly], since, new Set())).toEqual([recent, later])
  })

  it('never takes one shown before', () => {
    expect(forTheDesktop([delivered('a'), delivered('b')], since, new Set(['a']))).toEqual([delivered('b')])
  })
})
