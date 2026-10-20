import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import type { SyncedHandOver, SyncedMessage } from '@tresso/core'
import router from '@/router'
import { useComposeStore } from '@/stores/compose'
import { useHandOversStore } from '@/stores/handOvers'
import { useMailStore } from '@/stores/mail'

function message(id: string, from: string): SyncedMessage {
  return {
    id,
    mailAccountId: 'work',
    threadId: 't1',
    folderIds: ['inbox'],
    subject: 'Invoice question',
    from: { address: from, name: null },
    recipients: { to: [], cc: [], bcc: [], replyTo: [] },
    snippet: '',
    sentAt: null,
    receivedAt: '2026-10-19T16:40:00Z',
    flags: { seen: true, flagged: false, answered: false, draft: false },
    hasAttachments: false,
    smartInboxId: null,
    verdict: null,
    summary: null,
    calendarMethod: null,
    attachmentScan: null,
  }
}

const late: SyncedHandOver = {
  id: 'h1',
  mailAccountId: 'work',
  messageId: 'm1',
  threadId: 't1',
  subject: 'Invoice question',
  delegate: { address: 'lisa@company.example', name: 'Lisa Bakker' },
  note: '',
  dueAt: '2026-10-23T15:00:00Z',
  state: 'open',
  forwardId: 'f1',
  overdueAt: '2026-10-23T15:00:00Z',
  notifiedAt: null,
  notifiedChannels: [],
  notifiedSilently: false,
  nudgedAt: null,
  lastNudgeId: null,
  replyMessageId: null,
  createdAt: '2026-10-20T08:00:00Z',
  closedAt: null,
  updatedAt: '2026-10-23T15:00:00Z',
}

describe('hand-overs store', () => {
  let mail: ReturnType<typeof useMailStore>

  beforeEach(() => {
    setActivePinia(createPinia())
    mail = useMailStore()
    mail.accounts = [{ id: 'work', emailAddress: 'kim@company.example', displayName: 'Work', provider: 'imap', status: 'active', statusDetail: null, lastSyncedAt: null, defaultSignatureId: null, calendarStatus: 'unknown', calendarStatusDetail: null }]
  })

  it("hands over a conversation's newest message from someone else", async () => {
    vi.spyOn(mail, 'thread').mockResolvedValue({
      thread: { id: 't1', mailAccountId: 'work', subject: 'Invoice question', messageCount: 3, lastMessageAt: '2026-10-19T18:00:00Z', smartInboxId: null, verdict: null, classificationReason: null },
      messages: [message('m1', 'hotel@duinzicht.example'), message('m2', 'anna@duinzicht.example'), message('m3', 'kim@company.example')],
    })
    const handOvers = useHandOversStore()

    await handOvers.openForThread('t1')

    expect(handOvers.handingOver?.id).toBe('m2')
  })

  it('says so when the conversation is gone', async () => {
    vi.spyOn(mail, 'thread').mockResolvedValue(null)
    const handOvers = useHandOversStore()

    await handOvers.openForThread('t1')

    expect(handOvers.handingOver).toBeNull()
    expect(useComposeStore().notices.map((notice) => notice.text)).toEqual(['This conversation no longer exists.'])
  })

  it('tells the user about a hand-over past its due date, with the nudge one click away', async () => {
    useHandOversStore()
    const push = vi.spyOn(router, 'push').mockResolvedValue(undefined)

    mail.overdue = [late]
    await nextTick()

    const notice = useComposeStore().notices.find((candidate) => candidate.action?.label === 'Nudge')
    expect(notice?.text).toBe('No reply from Lisa Bakker on "Invoice question" yet.')
    expect(mail.overdue).toEqual([])
    notice!.action!.run()
    expect(push).toHaveBeenCalledWith({ name: 'waiting', query: { nudge: 'h1' } })
  })
})
