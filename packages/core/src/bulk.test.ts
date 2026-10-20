import { describe, expect, it } from 'vitest'
import {
  bulkActionLabel,
  bulkActionRunning,
  bulkPollDelay,
  bulkProgressText,
  bulkResultText,
  bulkSelectionQuery,
  cleanupSuggestionText,
  formatBytes,
  readRate,
  type BulkActionState,
  type CleanupSuggestion,
} from './bulk'

const finished: Pick<BulkActionState, 'action' | 'status' | 'conversations' | 'changed' | 'unrestorable' | 'restored' | 'error'> = {
  action: 'archive',
  status: 'done',
  conversations: 12,
  changed: 14,
  unrestorable: 0,
  restored: 0,
  error: null,
}

function suggestion(override: Partial<CleanupSuggestion>): CleanupSuggestion {
  return {
    id: 'newsletter:news@shop.example',
    kind: 'newsletter',
    conversations: 3,
    unread: 4,
    bytes: null,
    sender: 'news@shop.example',
    senderName: 'Shop',
    smartInboxId: null,
    windowDays: 90,
    windowReceived: 12,
    olderThanDays: null,
    largerThan: null,
    action: 'archive',
    scope: { in: 'inbox', smartInboxId: null, sender: 'news@shop.example', olderThanDays: null, largerThan: null },
    ...override,
  }
}

describe('bulk selection query', () => {
  it('carries only the filters that are set', () => {
    expect(bulkSelectionQuery({ in: 'smartInbox', smartInboxId: 's1', sender: null, olderThanDays: 30 })).toBe('in=smartInbox&smartInboxId=s1&olderThanDays=30')
  })

  it('encodes addresses', () => {
    expect(bulkSelectionQuery({ in: 'inbox', sender: 'a+b@shop.example' })).toBe('in=inbox&sender=a%2Bb%40shop.example')
  })
})

describe('bulk action words', () => {
  it('name the action with its count', () => {
    expect(bulkActionLabel('archive', 1)).toBe('Archive one conversation')
    expect(bulkActionLabel('markRead', 1200)).toBe('Mark 1,200 conversations read')
    expect(bulkActionLabel('move', 3, 'Receipts')).toBe('Move 3 conversations to Receipts')
  })

  it('tell how far a run got', () => {
    expect(bulkProgressText({ action: 'trash', status: 'running', conversations: 400, restored: 0 })).toBe('Moving to Trash: 400 conversations done')
    expect(bulkProgressText({ action: 'trash', status: 'undoing', conversations: 400, restored: 20 })).toBe('Putting back: 20 messages so far')
  })

  it('say what an undo cannot put back', () => {
    expect(bulkResultText(finished)).toBe('Archived: 12 conversations.')
    expect(bulkResultText({ ...finished, unrestorable: 2 })).toBe('Archived: 12 conversations. Undo cannot put 2 messages back.')
    expect(bulkResultText({ ...finished, conversations: 0, changed: 0 })).toBe('Nothing to do: no conversations matched.')
  })

  it('report a stop and an undo', () => {
    expect(bulkResultText({ ...finished, status: 'failed', conversations: 200, error: 'The smart inbox was deleted.' })).toBe('Stopped after 200 conversations: The smart inbox was deleted.')
    expect(bulkResultText({ ...finished, status: 'undone', restored: 1 })).toBe('Put back one message.')
  })

  it('know when a run is over', () => {
    expect(bulkActionRunning({ status: 'running' })).toBe(true)
    expect(bulkActionRunning({ status: 'undoing' })).toBe(true)
    expect(bulkActionRunning({ status: 'failed' })).toBe(false)
  })

  it('ask less often the longer a run takes', () => {
    expect([0, 1, 2, 3, 10].map(bulkPollDelay)).toEqual([500, 1000, 2000, 4000, 4000])
  })
})

describe('numbers', () => {
  it('format sizes', () => {
    expect(formatBytes(512)).toBe('512 bytes')
    expect(formatBytes(5 * 1024 * 1024)).toBe('5 MB')
    expect(formatBytes(1536)).toBe('1.5 KB')
    expect(formatBytes(23 * 1024 ** 3)).toBe('23 GB')
  })

  it('give the read rate of a sender', () => {
    expect(readRate({ messages: 10, unread: 9 })).toBe(10)
    expect(readRate({ messages: 0, unread: 0 })).toBe(0)
  })
})

describe('cleanup suggestions', () => {
  it('explain the rule behind a sender suggestion', () => {
    expect(cleanupSuggestionText(suggestion({}))).toEqual({
      title: 'Newsletters from Shop',
      detail: '4 unread in your inbox, and you opened none of the 12 messages they sent in the last 90 days.',
      button: 'Archive 3 conversations',
    })
    expect(cleanupSuggestionText(suggestion({ kind: 'unopenedSender', senderName: null })).title).toBe('Mail from news@shop.example')
    expect(cleanupSuggestionText(suggestion({ windowReceived: 1 })).detail).toBe('4 unread in your inbox, and you opened none of the one message they sent in the last 90 days.')
  })

  it('explain old mail in a smart inbox', () => {
    const text = cleanupSuggestionText(suggestion({ kind: 'oldInSmartInbox', sender: null, senderName: null, smartInboxId: 's1', olderThanDays: 30, conversations: 120 }), 'Notifications')
    expect(text.title).toBe('Notifications older than a month')
    expect(text.detail).toBe('120 conversations in Notifications had no new mail for over a month.')
  })

  it('explain large old mail', () => {
    const text = cleanupSuggestionText(
      suggestion({ kind: 'largeOld', action: 'trash', sender: null, senderName: null, olderThanDays: 365, largerThan: 5 * 1024 * 1024, bytes: 80 * 1024 * 1024, conversations: 9 }),
    )
    expect(text).toEqual({
      title: 'Large mail older than a year',
      detail: '9 conversations with a message of 5 MB or more, 80 MB in all, and no new mail for over a year.',
      button: 'Move to Trash',
    })
  })
})
