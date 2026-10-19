import { describe, expect, it } from 'vitest'
import { summarizeConversation } from './conversation'
import type { SyncedMessage } from './types'

const thread = { id: 't1', mailAccountId: 'work', subject: 'Offsite', messageCount: 3, lastMessageAt: '2026-10-19T09:00:00Z' }

function message(id: string, from: string, receivedAt: string, extra: Partial<SyncedMessage> = {}): SyncedMessage {
  return {
    id,
    mailAccountId: 'work',
    threadId: 't1',
    folderIds: ['sent'],
    subject: 'Offsite',
    from: { address: from, name: null },
    recipients: { to: [], cc: [], bcc: [], replyTo: [] },
    snippet: `Snippet ${id}`,
    sentAt: null,
    receivedAt,
    flags: { seen: true, flagged: false, answered: false, draft: false },
    hasAttachments: false,
    categoryId: null,
    verdict: null,
    summary: null,
    ...extra,
  }
}

describe('summarizeConversation', () => {
  it('sums a thread up from its newest message and its senders', () => {
    const summary = summarizeConversation(
      thread,
      [
        message('m1', 'anna@example.test', '2026-10-19T07:00:00Z'),
        message('m2', 'ANNA@example.test', '2026-10-19T08:00:00Z', { flags: { seen: false, flagged: true, answered: false, draft: false } }),
        message('m3', 'kim@example.test', '2026-10-19T11:00:00+02:00', { folderIds: ['inbox'], hasAttachments: true }),
      ],
      new Set(['inbox']),
    )

    expect(summary).toEqual({
      threadId: 't1',
      mailAccountId: 'work',
      subject: 'Offsite',
      snippet: 'Snippet m3',
      lastMessageAt: Date.parse('2026-10-19T09:00:00Z'),
      messageCount: 3,
      unreadCount: 1,
      flagged: true,
      hasAttachments: true,
      senders: [
        { address: 'kim@example.test', name: null },
        { address: 'ANNA@example.test', name: null },
      ],
      inbox: true,
    })
  })

  it('has nothing to show for a thread without messages, and knows when it is not in an inbox', () => {
    expect(summarizeConversation(thread, [], new Set(['inbox']))).toBeNull()
    expect(summarizeConversation(thread, [message('m1', 'a@example.test', '2026-10-19T07:00:00Z')], new Set(['inbox']))?.inbox).toBe(false)
  })
})
