import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type { Draft } from '@/mail/db'
import { useComposeStore } from '@/stores/compose'
import { useMailStore } from '@/stores/mail'
import ComposeForm from '../ComposeForm.vue'

const draft: Draft = { id: 'd1', mailAccountId: 'work', to: [], cc: [], bcc: [], subject: 'Hello', html: '<p>Hi</p>', signatureId: null, quoteHtml: '', inReplyToMessageId: null, kind: 'new', version: 0, pendingSave: false, remoteVersion: null, outgoingId: null, noticedStatus: null, updatedAt: 1 }

async function settle(): Promise<void> {
  for (let i = 0; i < 5; i++) {
    await flushPromises()
    await new Promise((resolve) => setTimeout(resolve, 0))
  }
}

function setup(initial: Draft) {
  setActivePinia(createPinia())
  const mail = useMailStore()
  mail.accounts = [{ id: 'work', emailAddress: 'kim@company.example', displayName: 'Kim', provider: 'imap', status: 'active', statusDetail: null, lastSyncedAt: null, defaultSignatureId: null }]
  const compose = useComposeStore()
  const send = vi.fn<(sendAt?: Date) => Promise<void>>(async () => {})
  compose.send = send
  compose.update = vi.fn<(changes: Partial<Draft>) => void>()
  const wrapper = mount(ComposeForm, { props: { draft: initial }, attachTo: document.body, global: { stubs: { RouterLink: true } } })
  return { wrapper, send }
}

describe('ComposeForm', () => {
  it('does not send without recipients and says why', async () => {
    const { wrapper, send } = setup(draft)

    await wrapper.get('form').trigger('submit')
    await settle()

    expect(send).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Add at least one recipient.')
    wrapper.unmount()
  })

  it('sends a valid message', async () => {
    const { wrapper, send } = setup({ ...draft, to: [{ address: 'anna@finance.example', name: 'Anna' }] })

    await wrapper.get('form').trigger('submit')
    await settle()

    expect(send).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('refuses an account-less draft', async () => {
    const { wrapper, send } = setup({ ...draft, mailAccountId: null, to: [{ address: 'anna@finance.example', name: null }] })

    await wrapper.get('form').trigger('submit')
    await settle()

    expect(send).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Choose the account to send from.')
    wrapper.unmount()
  })
})
