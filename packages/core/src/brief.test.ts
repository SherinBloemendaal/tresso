import { describe, expect, it } from 'vitest'
import { briefProgress, briefView, groupBrief, latestBrief, threadsToNote } from './brief'
import { dailyBrief, smartInbox } from './store/contract'
import type { ConversationSummary } from './sync/conversation'

function conversation(threadId: string, extra: Partial<ConversationSummary> = {}): ConversationSummary {
  return {
    threadId,
    mailAccountId: 'work',
    subject: `Subject ${threadId}`,
    snippet: `Snippet ${threadId}`,
    lastMessageAt: 0,
    messageCount: 1,
    unreadCount: 1,
    flagged: false,
    hasAttachments: false,
    attachmentsBlocked: false,
    senders: [{ address: `${threadId}@sender.example`, name: null }],
    inbox: true,
    active: true,
    smartInboxId: 'info',
    verdict: 'know',
    classificationReason: 'mailing_list',
    ...extra,
  }
}

const smartInboxes = [smartInbox('info', 'informational', 1), smartInbox('notes', 'notification', 2), smartInbox('ads', 'advertise', 3)]

describe('the daily brief', () => {
  it('shows the conversations it still has, in its order, each once', () => {
    const brief = dailyBrief('b1', '2026-10-20T08:00:00Z', ['t2', 'gone', 't1', 't2'])

    const view = briefView(brief, [conversation('t1'), conversation('t2', { inbox: false })])

    expect(view.entries.map((entry) => [entry.conversation.threadId, entry.noted])).toEqual([
      ['t2', true],
      ['t1', false],
    ])
  })

  it('groups per smart inbox in sidebar order, the ones without one last', () => {
    const { entries } = briefView(dailyBrief('b1', '2026-10-20T08:00:00Z', ['t1', 't2', 't3', 't4']), [
      conversation('t1', { smartInboxId: 'notes' }),
      conversation('t2', { smartInboxId: null }),
      conversation('t3', { smartInboxId: 'info' }),
      conversation('t4', { smartInboxId: 'notes' }),
    ])

    const groups = groupBrief(entries, 'smartInbox', smartInboxes)

    expect(groups.map((group) => [group.label, group.entries.map((entry) => entry.conversation.threadId)])).toEqual([
      ['info', ['t3']],
      ['notes', ['t1', 't4']],
      ['Other', ['t2']],
    ])
  })

  it('groups per sender, the busiest first, named when the sender has a name', () => {
    const anna = { address: 'Anna@Partner.example', name: 'Anna' }
    const { entries } = briefView(dailyBrief('b1', '2026-10-20T08:00:00Z', ['t1', 't2', 't3']), [
      conversation('t1'),
      conversation('t2', { senders: [anna] }),
      conversation('t3', { senders: [{ ...anna, address: 'anna@partner.example' }] }),
    ])

    const groups = groupBrief(entries, 'sender', smartInboxes)

    expect(groups.map((group) => [group.key, group.label, group.entries.length])).toEqual([
      ['anna@partner.example', 'Anna', 2],
      ['t1@sender.example', 't1@sender.example', 1],
    ])
  })

  it('counts what is noted and lists what is left to note', () => {
    const { entries } = briefView(dailyBrief('b1', '2026-10-20T08:00:00Z', ['t1', 't2', 't3']), [conversation('t1'), conversation('t2', { inbox: false }), conversation('t3')])

    expect(briefProgress(entries)).toEqual({ noted: 1, total: 3 })
    expect(threadsToNote(entries)).toEqual(['t1', 't3'])
  })

  it('leaves a conversation moved to another verdict out of the count and out of what is left to note', () => {
    const { entries } = briefView(dailyBrief('b1', '2026-10-20T08:00:00Z', ['t1', 't2', 't3']), [
      conversation('t1'),
      conversation('t2', { verdict: 'do', smartInboxId: 'todo', classificationReason: 'user' }),
      conversation('t3', { inbox: false }),
    ])

    expect(entries.map((entry) => [entry.conversation.threadId, entry.noted, entry.moved])).toEqual([
      ['t1', false, false],
      ['t2', false, true],
      ['t3', true, false],
    ])
    expect(briefProgress(entries)).toEqual({ noted: 1, total: 2 })
    expect(threadsToNote(entries)).toEqual(['t1'])
  })

  it('finds the newest brief by the end of its period', () => {
    expect(latestBrief([])).toBeNull()
    expect(latestBrief([dailyBrief('b1', '2026-10-19T08:00:00Z', []), dailyBrief('b2', '2026-10-20T09:00:00+02:00', []), dailyBrief('b0', '2026-10-18T08:00:00Z', [])])?.id).toBe('b2')
  })
})
