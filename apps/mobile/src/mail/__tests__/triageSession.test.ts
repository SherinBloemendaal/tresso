import { describe, expect, it, vi } from 'vitest'
import type { ConversationSummary, HandedOver, SyncedMessage, SyncedThread, TriageNotice } from '@tresso/core'
import { copyChanged, createTriageDriver, createTriageSession, type TriageHost } from '../triageSession'

const thread: SyncedThread = { id: 't1', mailAccountId: 'work', subject: 'Budget', messageCount: 1, lastMessageAt: '2026-10-19T08:00:00Z', smartInboxId: 'info', verdict: 'know', classificationReason: 'direct' }
const message: SyncedMessage = {
  id: 'm1',
  mailAccountId: 'work',
  threadId: 't1',
  folderIds: ['inbox'],
  subject: 'Budget',
  from: { address: 'anna@partner.example', name: null },
  recipients: { to: [], cc: [], bcc: [], replyTo: [] },
  snippet: '',
  sentAt: null,
  receivedAt: '2026-10-19T08:00:00Z',
  flags: { seen: true, flagged: false, answered: false, draft: false },
  hasAttachments: false,
  smartInboxId: 'info',
  verdict: 'know',
  summary: null,
  calendarMethod: null,
  attachmentScan: null,
}
const summary: ConversationSummary = {
  threadId: 't1',
  mailAccountId: 'work',
  subject: 'Budget',
  snippet: '',
  lastMessageAt: Date.parse('2026-10-19T08:00:00Z'),
  messageCount: 1,
  unreadCount: 0,
  flagged: false,
  hasAttachments: false,
  attachmentsBlocked: false,
  heldUntil: null,
  batchedAt: null,
  senders: [],
  inbox: true,
  active: true,
  smartInboxId: 'info',
  verdict: 'know',
  classificationReason: 'direct',
}

const handed = { forwardId: 'f1', draftId: 'd1', until: 0, handOver: { id: 'h1' } } as unknown as HandedOver

function host(overrides: Partial<TriageHost> = {}) {
  const calls: string[] = []
  const notices: TriageNotice[] = []
  const value: TriageHost = {
    mail: {
      triageWaiting: async () => ['t1'],
      newestInInbox: async () => [summary],
      summaries: async () => [summary],
      thread: async () => ({ thread, messages: [message] }),
      smartInboxes: async () => [{ id: 'info', builtin: 'informational', name: 'Informational', instructions: '', includeSenders: [], excludeSenders: [], verdict: 'know', autoCreateTodo: false, position: 0 }],
      folders: async () => [],
      classifyThread: async (threadId, smartInboxId, verdict, remember) => {
        calls.push(`place ${threadId} ${verdict} remember=${remember}`)
        return { threadId, smartInboxId, verdict, reason: 'user', senderRule: null, placementId: 'p1' }
      },
      releaseThread: async (threadId, placementId) => {
        calls.push(`release ${threadId} ${placementId}`)
        return 'released'
      },
      makeTodo: async (messageId, request) => {
        calls.push(`todo ${messageId} ${JSON.stringify(request)}`)
        return { id: 'todo-1', title: 'Budget', notes: '', dueDate: null, dueTime: null, durationMinutes: null, completedAt: null, position: 0, source: 'manual', messageId, threadId: 't1', createdAt: '', updatedAt: '', made: true }
      },
      removeTodo: async (id) => {
        calls.push(`remove ${id}`)
      },
      mailAccounts: async () => [],
    },
    handOvers: {
      handOverAndWait: async (threadId) => {
        calls.push(`hand over ${threadId}`)
        return handed
      },
      takeBack: async (taken) => {
        calls.push(`take back ${taken.handOver.id}`)
        return 'undone'
      },
    },
    notify: (notice) => notices.push(notice),
    run: async (ids, action) => {
      calls.push(`${action} ${ids.join(',')}`)
      return { before: [], after: [] }
    },
    undoMoves: async () => [],
    hooks: {},
    ...overrides,
  }
  return { value, calls, notices }
}

describe("the phone's triage", () => {
  it('starts only once the copy has synced and the smart inboxes are there, and follows after that', async () => {
    const { value } = host()
    const session = createTriageSession(() => value, () => {})
    const begin = vi.spyOn(session, 'begin')
    const follow = vi.spyOn(session, 'follow')

    expect(copyChanged(session, false, { synced: false, smartInboxes: 3 })).toBe(false)
    expect(copyChanged(session, false, { synced: true, smartInboxes: 0 })).toBe(false)
    expect(begin).not.toHaveBeenCalled()

    expect(copyChanged(session, false, { synced: true, smartInboxes: 3 })).toBe(true)
    expect(copyChanged(session, true, { synced: true, smartInboxes: 3 })).toBe(true)
    expect(begin).toHaveBeenCalledOnce()
    expect(follow).toHaveBeenCalledOnce()
  })

  it("makes the conversation's todo on do, and undo removes it before the placement is taken back", async () => {
    const { value, calls } = host()
    const session = createTriageSession(() => value, () => {})
    await session.begin()

    session.decide('do')
    await session.idle()
    session.undo()
    await session.idle()

    expect(calls).toEqual(['place t1 do remember=false', 'todo m1 {"perConversation":true}', 'remove todo-1', 'release t1 p1'])
  })

  it('hands over through the hand-over screen, placing only once it was handed over, and undo takes it back', async () => {
    const { value, calls } = host()
    const session = createTriageSession(() => value, () => {})
    await session.begin()

    session.decide('delegate')
    await session.idle()
    session.undo()
    await session.idle()

    expect(calls).toEqual(['hand over t1', 'place t1 delegate remember=false', 'take back h1', 'release t1 p1'])
  })

  it('keeps going while the hand-over screen is open', async () => {
    let close!: (outcome: HandedOver | null) => void
    const base = host()
    const value = {
      ...base.value,
      mail: { ...base.value.mail, triageWaiting: async () => ['t1', 't2'] },
      handOvers: {
        ...base.value.handOvers,
        handOverAndWait: () =>
          new Promise<HandedOver | null>((resolve) => {
            close = resolve
          }),
      },
    }
    const session = createTriageSession(() => value, () => {})
    await session.begin()

    session.decide('delegate')
    session.decide('know')
    await vi.waitFor(() => expect(base.calls).toContain('archive m1'))
    expect(base.calls.some((call) => call.startsWith('place'))).toBe(false)
    close(null)
  })

  it('places nothing when the hand-over screen closes without a hand-over', async () => {
    const { value, calls } = host({ handOvers: { handOverAndWait: async () => null, takeBack: async () => 'undone' } })
    const session = createTriageSession(() => value, () => {})
    await session.begin()

    session.decide('delegate')
    await session.idle()

    expect(calls).toEqual([])
    expect(session.state?.decided).toEqual([])
  })

  it('uses a hand-over hook given instead of the screen', async () => {
    const handOver = vi.fn(async () => null)
    const { value, calls } = host({ hooks: { handOver } })
    const session = createTriageSession(() => value, () => {})
    await session.begin()

    session.decide('delegate')
    await session.idle()

    expect(handOver).toHaveBeenCalledOnce()
    expect(calls).toEqual([])
  })

  it('reads the host the screen handed it last', async () => {
    const driver = createTriageDriver(() => {})
    await expect(driver.session.begin()).rejects.toThrow('Triage started before the screen was ready.')

    const first = host({ mail: { ...host().value.mail, triageWaiting: async () => [] } })
    const second = host()
    driver.setHost(first.value)
    driver.setHost(second.value)
    await driver.session.begin()

    expect(driver.session.state?.queue).toEqual(['t1'])
  })
})
