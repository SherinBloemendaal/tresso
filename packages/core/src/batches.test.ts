import { describe, expect, it } from 'vitest'
import { batchGroups, batchTimeLabel, oneClickUnsubscribeAvailable, unsubscribeOffer } from './batches'
import { BATCHES_PATH, mailNotificationTarget } from './notifications'
import type { SyncedMessage, SyncedUnsubscription } from './sync/types'

function message(id: string, receivedAt: string, extra: Partial<SyncedMessage> = {}): SyncedMessage {
  return {
    id,
    mailAccountId: 'work',
    threadId: 't1',
    folderIds: ['inbox'],
    subject: 'Deals',
    from: { address: 'News@Shop.example', name: 'Shop' },
    recipients: { to: [], cc: [], bcc: [], replyTo: [] },
    snippet: '',
    sentAt: null,
    receivedAt,
    flags: { seen: false, flagged: false, answered: false, draft: false },
    hasAttachments: false,
    attachmentScan: null,
    smartInboxId: null,
    verdict: null,
    summary: null,
    calendarMethod: null,
    ...extra,
  }
}

function unsubscription(status: SyncedUnsubscription['status'], extra: Partial<SyncedUnsubscription> = {}): SyncedUnsubscription {
  return { id: 'u1', mailAccountId: 'work', sender: 'news@shop.example', method: 'oneClick', status, error: null, requestedAt: '2026-10-19T12:00:00Z', completedAt: null, ...extra }
}

describe('unsubscribeOffer', () => {
  const messages = [
    message('m1', '2026-10-19T07:00:00Z', { unsubscribe: 'mailto' }),
    message('m2', '2026-10-19T09:00:00Z', { unsubscribe: 'oneClick' }),
    message('m3', '2026-10-19T10:00:00Z', { from: { address: 'kim@company.example', name: 'Kim' } }),
  ]

  it('offers the newest message that has a one-click way, for its account and sender', () => {
    expect(unsubscribeOffer(messages, [])).toEqual({ state: 'available', messageId: 'm2', mailAccountId: 'work', sender: 'news@shop.example', method: 'oneClick', recipient: null, error: null })
  })

  it('says where a mailto unsubscribe goes, so the user sees it before it is sent', () => {
    const mailto = [message('m1', '2026-10-19T07:00:00Z', { unsubscribe: 'mailto', unsubscribeMailto: 'leave@shop.example' })]

    expect(unsubscribeOffer(mailto, [])).toMatchObject({ state: 'available', method: 'mailto', recipient: 'leave@shop.example' })
  })

  it('stops offering it while a request is pending and once it is done', () => {
    expect(unsubscribeOffer(messages, [unsubscription('pending')])).toEqual({ state: 'pending', sender: 'news@shop.example' })
    expect(unsubscribeOffer(messages, [unsubscription('done')])).toEqual({ state: 'done', sender: 'news@shop.example' })
    expect(unsubscribeOffer(messages, [unsubscription('done', { mailAccountId: 'home' })])?.state).toBe('available')
  })

  it('offers it again after a failure, with the reason', () => {
    expect(unsubscribeOffer(messages, [unsubscription('failed', { error: 'Refused (HTTP 404).' })])).toMatchObject({ state: 'available', error: 'Refused (HTTP 404).' })
  })

  it('offers nothing when no message has a one-click way', () => {
    expect(unsubscribeOffer([message('m1', '2026-10-19T07:00:00Z')], [])).toBeNull()
  })
})

describe('batchTimeLabel', () => {
  it('says the time for a batch today and adds the day otherwise', () => {
    const now = Date.parse('2026-10-20T10:00:00Z')

    expect(batchTimeLabel(Date.parse('2026-10-20T16:00:00Z'), now, 'en-GB', 'UTC')).toBe('16:00')
    expect(batchTimeLabel(Date.parse('2026-10-21T09:00:00Z'), now, 'en-GB', 'UTC')).toBe('Wed 09:00')
  })
})

describe('oneClickUnsubscribeAvailable', () => {
  it('is there for accounts whose provider vouches for senders, not for generic IMAP servers', () => {
    expect(oneClickUnsubscribeAvailable({ provider: 'gmail' })).toBe(true)
    expect(oneClickUnsubscribeAvailable({ provider: 'microsoft' })).toBe(true)
    expect(oneClickUnsubscribeAvailable({ provider: 'imap' })).toBe(false)
  })
})

describe('batchGroups', () => {
  it('groups the batches list by the batch that delivered each conversation', () => {
    const list = [
      { id: 'a', batchedAt: 16 },
      { id: 'b', batchedAt: 16 },
      { id: 'c', batchedAt: 9 },
      { id: 'd', batchedAt: null },
    ]

    expect(batchGroups(list).map((group) => [group.batchedAt, group.conversations.map((c) => c.id)])).toEqual([
      [16, ['a', 'b']],
      [9, ['c']],
    ])
  })
})

describe('mailNotificationTarget', () => {
  it('opens the batches for a summary and the conversation otherwise', () => {
    expect(mailNotificationTarget({ threadId: null, summary: true })).toBe(BATCHES_PATH)
    expect(BATCHES_PATH).toBe('/batches')
    expect(mailNotificationTarget({ threadId: 't1' })).toBe('/thread/t1')
    expect(mailNotificationTarget({ threadId: null, summary: false })).toBeNull()
  })
})
