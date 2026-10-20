import { describe, expect, it } from 'vitest'
import type { SyncedHandOver, SyncedMailNotification } from '@tresso/core'
import { FRESHNESS_MS, forTheDesktop, mailNotifications, overdueNotifications } from '../mailNotifications'

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
    expect(mailNotifications([delivered('a'), delivered('b', { threadId: null, silent: true })])).toEqual([
      { title: 'Sender a', body: 'Subject a', path: '/thread/t-a', silent: false },
      { title: 'Sender b', body: 'Subject b', path: null, silent: true },
    ])
  })

  it("opens the batches for a batch's summary", () => {
    expect(mailNotifications([delivered('s', { threadId: null, title: '5 new messages to do', summary: true })])).toEqual([
      { title: '5 new messages to do', body: 'Subject s', path: '/batches', silent: false },
    ])
  })

  it('sums up when much arrives at once', () => {
    expect(mailNotifications(['a', 'b', 'c', 'd'].map((id) => delivered(id)))).toEqual([
      { title: '4 new messages need you', body: 'From Sender a, Sender b, Sender c and 1 more', path: null, silent: false },
    ])
    expect(mailNotifications(['a', 'a', 'b', 'b'].map((id, i) => delivered(`${id}${i}`, { title: `Sender ${id}`, silent: true })))).toEqual([
      { title: '4 new messages need you', body: 'From Sender a, Sender b', path: null, silent: true },
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

describe('overdueNotifications', () => {
  function late(id: string, name: string | null, silent = false): SyncedHandOver {
    return {
      id,
      mailAccountId: 'work',
      messageId: null,
      threadId: null,
      subject: `Subject ${id}`,
      delegate: { address: `${id}@company.example`, name },
      note: '',
      dueAt: '2026-10-23T15:00:00Z',
      state: 'open',
      forwardId: null,
      overdueAt: '2026-10-23T15:00:00Z',
      notifiedAt: '2026-10-23T15:00:00Z',
      notifiedChannels: ['desktop'],
      notifiedSilently: silent,
      nudgedAt: null,
      lastNudgeId: null,
      replyMessageId: null,
      createdAt: '2026-10-20T08:00:00Z',
      closedAt: null,
      updatedAt: '2026-10-23T15:00:00Z',
    }
  }

  it('names who has not replied, about what, and opens the follow-up', () => {
    expect(overdueNotifications([late('lisa', 'Lisa Bakker'), late('tom', null, true)])).toEqual([
      { title: 'No reply from Lisa yet', body: 'Subject lisa is past its due date. Nudge from Waiting on.', path: '/waiting?nudge=lisa', silent: false },
      { title: 'No reply from tom yet', body: 'Subject tom is past its due date. Nudge from Waiting on.', path: '/waiting?nudge=tom', silent: true },
    ])
  })

  it('sums up many at once, quiet only when each came quietly', () => {
    expect(overdueNotifications(['a', 'b', 'c', 'd'].map((id) => late(id, null, id !== 'a')))).toEqual([{ title: '4 hand-overs are past their due date', body: 'Nudge from Waiting on.', path: '/waiting', silent: false }])
  })
})
