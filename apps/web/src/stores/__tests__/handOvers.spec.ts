import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import { HandOverFlow, type HandedOver, type SyncedHandOver, type SyncedMessage } from '@tresso/core'
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

  describe('for triage', () => {
    afterEach(() => {
      vi.restoreAllMocks()
    })

    const conversation = {
      thread: { id: 't1', mailAccountId: 'work', subject: 'Invoice question', messageCount: 2, lastMessageAt: '2026-10-19T18:00:00Z', smartInboxId: null, verdict: null, classificationReason: null },
      messages: [message('m1', 'hotel@duinzicht.example'), message('m2', 'kim@company.example')],
    }
    const input = { delegate: { address: 'lisa@company.example', name: 'Lisa Bakker' }, note: '', dueAt: new Date('2026-10-23T15:00:00Z'), dueLabel: 'Friday' }

    it('settles with nothing when the dialog closes without a hand-over', async () => {
      vi.spyOn(mail, 'thread').mockResolvedValue(conversation)
      const handOvers = useHandOversStore()

      const waiting = handOvers.handOverAndWait('t1')
      await vi.waitFor(() => expect(handOvers.handingOver?.id).toBe('m1'))
      handOvers.close()

      expect(await waiting).toBeNull()
    })

    it('settles with the hand-over and leaves the notice to triage', async () => {
      vi.spyOn(mail, 'thread').mockResolvedValue(conversation)
      const handed = { handOver: late, forwardId: 'f1', draftId: 'd1', until: Date.now() + 5000 } satisfies HandedOver
      vi.spyOn(HandOverFlow.prototype, 'handOver').mockResolvedValue(handed)
      const handOvers = useHandOversStore()

      const waiting = handOvers.handOverAndWait('t1')
      await vi.waitFor(() => expect(handOvers.handingOver).not.toBeNull())
      await handOvers.handOver(handOvers.handingOver!, input)

      expect(await waiting).toBe(handed)
      expect(handOvers.handingOver).toBeNull()
      expect(useComposeStore().notices).toEqual([])
    })

    it('takes a hand-over back as far as it still can', async () => {
      const takeBack = vi.spyOn(HandOverFlow.prototype, 'takeBack').mockResolvedValue('withdrawn')
      const handed = { handOver: late, forwardId: 'f1', draftId: 'd1', until: 0 } satisfies HandedOver

      expect(await useHandOversStore().takeBack(handed)).toBe('withdrawn')
      expect(takeBack).toHaveBeenCalledWith(handed)
    })
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
