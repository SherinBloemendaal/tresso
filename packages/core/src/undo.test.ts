import { describe, expect, it } from 'vitest'
import type { SyncedFolder, SyncedMessage } from './sync/types'
import { undoPlan } from './actions'

const folders: SyncedFolder[] = [
  { id: 'inbox', mailAccountId: 'work', name: 'INBOX', displayName: 'Inbox', role: 'inbox' },
  { id: 'archive', mailAccountId: 'work', name: 'Archive', displayName: 'Archive', role: 'archive' },
  { id: 'trash', mailAccountId: 'work', name: 'Trash', displayName: 'Trash', role: 'trash' },
  { id: 'projects', mailAccountId: 'work', name: 'Projects', displayName: 'Projects', role: 'custom' },
  { id: 'sent', mailAccountId: 'work', name: 'Sent', displayName: 'Sent', role: 'sent' },
  { id: 'all', mailAccountId: 'work', name: '[Gmail]/All Mail', displayName: 'All Mail', role: 'all' },
]

function message(id: string, folderIds: string[]): SyncedMessage {
  return {
    id,
    mailAccountId: 'work',
    threadId: 't1',
    folderIds,
    subject: '',
    from: { address: 'a@example.test', name: null },
    recipients: { to: [], cc: [], bcc: [], replyTo: [] },
    snippet: '',
    sentAt: null,
    receivedAt: '2026-10-19T08:00:00Z',
    flags: { seen: true, flagged: false, answered: false, draft: false },
    hasAttachments: false,
    smartInboxId: null,
    verdict: null,
    summary: null,
  }
}

describe('undoPlan', () => {
  it('puts archived mail back in its inbox and leaves the rest alone', () => {
    const plan = undoPlan(
      {
        before: [message('m1', ['inbox', 'projects']), message('m2', ['sent'])],
        after: [message('m1', ['projects', 'archive']), message('m2', ['sent'])],
      },
      'archive',
      folders,
    )

    expect(plan).toEqual({ exact: true, unrestorable: 0, moves: [{ fromFolderId: 'archive', folderId: 'inbox', messageIds: ['m1'] }] })
  })

  it('puts trashed mail back per folder it came from, counting Gmail labels as one', () => {
    const plan = undoPlan(
      {
        before: [message('m1', ['inbox', 'all']), message('m2', ['sent']), message('m3', ['inbox'])],
        after: [message('m1', ['trash']), message('m2', ['trash']), message('m3', ['trash'])],
      },
      'trash',
      folders,
    )

    expect(plan.exact).toBe(true)
    expect(plan.moves).toEqual([
      { fromFolderId: 'trash', folderId: 'inbox', messageIds: ['m1', 'm3'] },
      { fromFolderId: 'trash', folderId: 'sent', messageIds: ['m2'] },
    ])
  })

  it('leaves out mail that was in several folders, or archived into All Mail, and counts it', () => {
    const partial = undoPlan(
      { before: [message('m1', ['inbox', 'projects']), message('m2', ['inbox'])], after: [message('m1', ['trash']), message('m2', ['trash'])] },
      'trash',
      folders,
    )
    expect(partial).toEqual({ exact: false, unrestorable: 1, moves: [{ fromFolderId: 'trash', folderId: 'inbox', messageIds: ['m2'] }] })
    expect(undoPlan({ before: [message('m1', ['inbox', 'projects'])], after: [message('m1', ['trash'])] }, 'trash', folders).exact).toBe(false)
    expect(undoPlan({ before: [message('m1', ['inbox', 'all'])], after: [message('m1', ['all'])] }, 'archive', folders).exact).toBe(false)
    // A copy already in Archive would go back to the inbox along with the archived one.
    expect(undoPlan({ before: [message('m1', ['inbox', 'archive'])], after: [message('m1', ['archive'])] }, 'archive', folders).exact).toBe(false)
  })

  it('has nothing to undo when nothing moved', () => {
    expect(undoPlan({ before: [message('m1', ['trash'])], after: [message('m1', ['trash'])] }, 'trash', folders)).toEqual({ exact: true, unrestorable: 0, moves: [] })
  })
})
