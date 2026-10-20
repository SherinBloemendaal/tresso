import { describe, expect, it } from 'vitest'
import { actionResult, type AppliedAction, type MessageAction, type SyncedFolder, type SyncedMessage } from '@tresso/core'
import type { Notice } from '@/components/Notices'
import { noteThreads, type NotedDeps } from '../noted'

const folders: SyncedFolder[] = [
  { id: 'inbox', mailAccountId: 'work', name: 'INBOX', displayName: 'Inbox', role: 'inbox' },
  { id: 'archive', mailAccountId: 'work', name: 'Archive', displayName: 'Archive', role: 'archive' },
]
const accounts = [{ id: 'work', emailAddress: 'kim@company.example', displayName: 'Work', provider: 'imap', status: 'active' as const, statusDetail: null, lastSyncedAt: null, defaultSignatureId: null, calendarStatus: 'unknown' as const, calendarStatusDetail: null }]

function message(id: string, seen: boolean): SyncedMessage {
  return {
    id,
    mailAccountId: 'work',
    threadId: 't1',
    folderIds: ['inbox'],
    subject: 'Release notes',
    from: { address: 'news@lists.example', name: null },
    recipients: { to: [], cc: [], bcc: [], replyTo: [] },
    snippet: '',
    sentAt: null,
    receivedAt: '2026-10-20T07:00:00Z',
    flags: { seen, flagged: false, answered: false, draft: false },
    hasAttachments: false,
    smartInboxId: 'info',
    verdict: 'know',
    summary: null,
    calendarMethod: null,
    attachmentScan: null,
  }
}

/** A phone with two messages in one conversation, one of them read, whose server takes or refuses each action. */
function phone(refuse: MessageAction[] = []) {
  let messages = [message('m1', false), message('m2', true)]
  const calls: { action: MessageAction; messageIds: string[]; target?: object }[] = []
  const notices: Notice[] = []
  const act = async (messageIds: string[], action: MessageAction, target: { folderId?: string; fromFolderId?: string } = {}): Promise<AppliedAction> => {
    calls.push(Object.keys(target).length === 0 ? { action, messageIds } : { action, messageIds, target })
    if (refuse.includes(action)) {
      throw new Error('The server is busy.')
    }
    const before = messages.filter((m) => messageIds.includes(m.id))
    const after = actionResult(before, action, { folders, accounts, folderId: target.folderId, fromFolderId: target.fromFolderId })
    messages = messages.map((m) => after.find((changed) => changed.id === m.id) ?? m)
    return { before, after }
  }
  const deps: NotedDeps = {
    messageIdsOfThreads: async () => messages.map((m) => m.id),
    folders: async () => folders,
    run: async (messageIds, action) => {
      try {
        return await act(messageIds, action)
      } catch (error) {
        notices.push({ text: error instanceof Error ? error.message : String(error), tone: 'error' })
        return null
      }
    },
    act,
    notify: (notice) => notices.push(notice),
  }
  return { deps, calls, notices, messages: () => messages }
}

describe('noting on the phone', () => {
  it('marks the conversation read and archives it, and its undo puts back the folder and what was unread', async () => {
    const { deps, calls, notices, messages } = phone()

    await noteThreads(deps, ['t1'])

    expect(calls.map((call) => call.action)).toEqual(['markRead', 'archive'])
    expect(messages().map((m) => [m.flags.seen, m.folderIds])).toEqual([
      [true, ['archive']],
      [true, ['archive']],
    ])
    expect(notices).toHaveLength(1)
    expect(notices[0]!.text).toBe('Noted: read and archived.')

    notices[0]!.action!.run()
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(calls.slice(2)).toEqual([
      { action: 'move', messageIds: ['m1', 'm2'], target: { folderId: 'inbox', fromFolderId: 'archive' } },
      { action: 'markUnread', messageIds: ['m1'] },
    ])
    expect(messages().map((m) => [m.flags.seen, m.folderIds])).toEqual([
      [false, ['inbox']],
      [true, ['inbox']],
    ])
  })

  it('makes what was unread unread again when archiving fails, so nothing is half noted', async () => {
    const { deps, calls, notices, messages } = phone(['archive'])

    await noteThreads(deps, ['t1'])

    expect(calls.map((call) => [call.action, call.messageIds])).toEqual([
      ['markRead', ['m1', 'm2']],
      ['archive', ['m1', 'm2']],
      ['markUnread', ['m1']],
    ])
    expect(messages().map((m) => m.flags.seen)).toEqual([false, true])
    expect(notices.map((notice) => notice.tone)).toEqual(['error'])
  })

  it('does nothing without conversations', async () => {
    const { deps, calls } = phone()

    await noteThreads(deps, [])

    expect(calls).toEqual([])
  })
})
