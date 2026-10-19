import { describe, expect, it } from 'vitest'
import { inList, listFolders, summarizeConversation } from './conversation'
import type { SyncedMessage, SyncedThread } from './types'

const lists = listFolders(
  [
    { id: 'inbox', role: 'inbox' },
    { id: 'junk', role: 'spam' },
  ],
  [{ id: 'spam', builtin: 'spam' }],
)

const thread: SyncedThread = {
  id: 't1',
  mailAccountId: 'work',
  subject: 'Offsite',
  messageCount: 3,
  lastMessageAt: '2026-10-19T09:00:00Z',
  smartInboxId: 'todo',
  verdict: 'do',
  classificationReason: 'direct',
}

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
    smartInboxId: null,
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
      lists,
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
      active: true,
      smartInboxId: 'todo',
      verdict: 'do',
      classificationReason: 'direct',
    })
  })

  it('lists a thread in a junk folder in its smart inbox only', () => {
    const summary = summarizeConversation(thread, [message('m1', 'a@example.test', '2026-10-19T07:00:00Z', { folderIds: ['junk'] })], lists)

    expect(summary).toMatchObject({ inbox: false, active: true })
    expect(inList(summary!, { kind: 'smartInbox', smartInboxId: 'todo' })).toBe(true)
    expect(inList(summary!, { kind: 'inbox', mailAccountId: null })).toBe(false)
  })

  it('keeps spam out of the inboxes, also when the server left it there', () => {
    const summary = summarizeConversation({ ...thread, smartInboxId: 'spam' }, [message('m1', 'a@example.test', '2026-10-19T07:00:00Z', { folderIds: ['inbox'] })], lists)

    expect(summary).toMatchObject({ inbox: false, active: true })
    expect(inList(summary!, { kind: 'smartInbox', smartInboxId: 'spam' })).toBe(true)
  })

  it('lists an inbox thread in its account and the Unified Inbox, not in other accounts', () => {
    const summary = summarizeConversation(thread, [message('m1', 'a@example.test', '2026-10-19T07:00:00Z', { folderIds: ['inbox'] })], lists)!

    expect(inList(summary, { kind: 'inbox', mailAccountId: null })).toBe(true)
    expect(inList(summary, { kind: 'inbox', mailAccountId: 'work' })).toBe(true)
    expect(inList(summary, { kind: 'inbox', mailAccountId: 'home' })).toBe(false)
    expect(inList(summary, { kind: 'smartInbox', smartInboxId: 'other' })).toBe(false)
  })

  it('has nothing to show for a thread without messages, and knows when it is not in an inbox', () => {
    expect(summarizeConversation(thread, [], lists)).toBeNull()
    expect(summarizeConversation(thread, [message('m1', 'a@example.test', '2026-10-19T07:00:00Z')], lists)?.inbox).toBe(false)
  })
})
