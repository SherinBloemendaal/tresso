import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type { MessageBody, SyncedMessage } from '@tresso/core'
import { useMailStore } from '@/stores/mail'
import MessageCard from '../MessageCard.vue'

const message: SyncedMessage = {
  id: 'm1',
  mailAccountId: 'work',
  threadId: 't1',
  folderIds: ['inbox'],
  subject: 'Overdue invoice',
  from: { address: 'billing@duinzicht.example', name: 'Accounts' },
  recipients: { to: [], cc: [], bcc: [], replyTo: [] },
  snippet: 'Please open the attached invoice',
  sentAt: null,
  receivedAt: '2026-10-19T07:58:00Z',
  flags: { seen: true, flagged: false, answered: false, draft: false },
  hasAttachments: true,
  attachmentScan: 'pending',
  smartInboxId: null,
  verdict: null,
  summary: null,
  calendarMethod: null,
}

function body(scanStatus: 'pending' | 'infected'): MessageBody {
  return {
    id: 'm1',
    html: null,
    text: 'Please open the attached invoice.',
    hasRemoteContent: false,
    remoteContentLoaded: false,
    attachments: [
      {
        id: 'a1',
        filename: 'invoice.zip',
        contentType: 'application/zip',
        size: 412 * 1024,
        inline: false,
        contentId: null,
        scanStatus,
        access: scanStatus === 'pending' ? 'scanning' : 'blocked',
        scanSignature: scanStatus === 'infected' ? 'Win.Test.EICAR_HDB-1' : null,
        scanFailure: null,
        url: null,
      },
    ],
  }
}

describe('MessageCard', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('shows the scan result once the message says the scan is done', async () => {
    const mail = useMailStore()
    const load = vi.spyOn(mail, 'body').mockResolvedValueOnce(body('pending')).mockResolvedValueOnce(body('infected'))
    const wrapper = mount(MessageCard, { props: { message, expanded: true } })
    await flushPromises()
    expect(wrapper.text()).toContain('scanning…')
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)

    await wrapper.setProps({ message: { ...message, attachmentScan: 'infected' } })
    await flushPromises()

    expect(load).toHaveBeenCalledTimes(2)
    expect(wrapper.get('[role="alert"]').text()).toContain('An attachment contains malware.')
    expect(wrapper.text()).toContain('Blocked: malware found.')
  })

  it('picks up a finished file while another one is still being scanned', async () => {
    const mail = useMailStore()
    const twoFiles = (first: 'pending' | 'clean'): MessageBody => {
      const pending = body('pending')
      const done = { ...pending.attachments[0]!, id: 'a2', filename: 'statement.pdf', scanStatus: first, access: first === 'clean' ? ('allowed' as const) : ('scanning' as const), url: first === 'clean' ? 'http://localhost:8080/content/attachments/a2?_hash=x' : null }
      return { ...pending, attachments: [...pending.attachments, done] }
    }
    const load = vi.spyOn(mail, 'body').mockResolvedValueOnce(twoFiles('pending')).mockResolvedValueOnce(twoFiles('clean'))
    const wrapper = mount(MessageCard, { props: { message, expanded: true } })
    await flushPromises()

    await wrapper.setProps({ message: { ...message } })
    await flushPromises()

    expect(load).toHaveBeenCalledTimes(2)
    expect(wrapper.find('a[aria-label="Download statement.pdf"]').exists()).toBe(true)
  })

  it('does not reload a scanned body when something else about the message changes', async () => {
    const mail = useMailStore()
    const clean = body('pending')
    clean.attachments[0]!.scanStatus = 'clean'
    clean.attachments[0]!.access = 'allowed'
    const load = vi.spyOn(mail, 'body').mockResolvedValue(clean)
    const wrapper = mount(MessageCard, { props: { message: { ...message, attachmentScan: 'clean' }, expanded: true } })
    await flushPromises()

    await wrapper.setProps({ message: { ...message, attachmentScan: 'clean', flags: { ...message.flags, flagged: true } } })
    await flushPromises()

    expect(load).toHaveBeenCalledTimes(1)
  })
})
