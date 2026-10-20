import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type { SyncedSmartInbox } from '@tresso/core'
import { ApiError } from '@/api/errors'
import { useComposeStore } from '@/stores/compose'
import { useMailStore } from '@/stores/mail'
import ClassificationMenu from '../ClassificationMenu.vue'

function smartInbox(id: string, name: string, verdict: SyncedSmartInbox['verdict'], position: number): SyncedSmartInbox {
  return { id, builtin: null, name, instructions: '', includeSenders: [], excludeSenders: [], verdict, position }
}

describe('ClassificationMenu', () => {
  let mail: ReturnType<typeof useMailStore>
  let classify: ReturnType<typeof vi.spyOn>

  beforeEach(() => {
    setActivePinia(createPinia())
    mail = useMailStore()
    mail.smartInboxes = [smartInbox('todo', 'To do', 'do', 0), smartInbox('info', 'Informational', 'know', 1), smartInbox('news', 'Newsletters', 'know', 2)]
    classify = vi.spyOn(mail, 'classifyThread').mockResolvedValue({ threadId: 't1', smartInboxId: 'news', verdict: 'know', reason: 'user', senderRule: null })
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  function mountMenu(smartInboxId: string | null, verdict: SyncedSmartInbox['verdict'] | null) {
    return mount(ClassificationMenu, { props: { threadId: 't1', subject: 'Budget', smartInboxId, verdict, showSmartInbox: true }, attachTo: document.body })
  }

  it('shows the verdict and the smart inbox, and names both for screen readers', () => {
    const wrapper = mountMenu('info', 'know')

    expect(wrapper.text()).toContain('Know')
    expect(wrapper.text()).toContain('Informational')
    expect(wrapper.get('button').attributes('aria-label')).toBe('Know, in Informational. Change where Budget belongs')
    wrapper.unmount()
  })

  it('moves the conversation to another smart inbox with that inbox\'s verdict', async () => {
    const wrapper = mountMenu('info', 'know')

    await wrapper.get('button').trigger('click')
    const items = wrapper.findAll('[role="menuitemradio"]')
    expect(items.map((item) => item.attributes('aria-checked'))).toEqual(['false', 'false', 'true', 'false', 'false', 'true', 'false'])
    await items.find((item) => item.text().startsWith('Newsletters'))!.trigger('click')
    await flushPromises()

    expect(classify).toHaveBeenCalledWith('t1', 'news', undefined)
    wrapper.unmount()
  })

  it('changes only the verdict, keeping the smart inbox', async () => {
    const wrapper = mountMenu('info', 'know')

    await wrapper.get('button').trigger('click')
    await wrapper.findAll('[role="menuitemradio"]').find((item) => item.text() === 'Do')!.trigger('click')
    await flushPromises()

    expect(classify).toHaveBeenCalledWith('t1', 'info', 'do')
    wrapper.unmount()
  })

  it('sends an unsorted conversation to the first smart inbox with the chosen verdict', async () => {
    const wrapper = mountMenu(null, null)

    expect(wrapper.text()).toContain('Not sorted')
    await wrapper.get('button').trigger('click')
    await wrapper.findAll('[role="menuitemradio"]').find((item) => item.text() === 'Know')!.trigger('click')
    await flushPromises()

    expect(classify).toHaveBeenCalledWith('t1', 'info', 'know')
    wrapper.unmount()
  })

  it('says so when the server refuses', async () => {
    classify.mockRejectedValue(new ApiError(422, 'This smart inbox does not exist.'))
    const wrapper = mountMenu('info', 'know')

    await wrapper.get('button').trigger('click')
    await wrapper.findAll('[role="menuitemradio"]').find((item) => item.text().startsWith('To do'))!.trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toBe('Could not move it: This smart inbox does not exist.')
    wrapper.unmount()
  })

  it.each([
    ['authentication_failed', 'Moved. New mail from news@bank.example still goes to Spam: it fails the checks that prove who sent it.'],
    ['server_spam', 'Moved. Your mail server marks mail from news@bank.example as spam, so new mail from them still goes to Spam.'],
  ] as const)('says when new mail from the sender still goes to Spam (%s)', async (spamOverrules, text) => {
    classify.mockResolvedValue({ threadId: 't1', smartInboxId: 'info', verdict: 'know', reason: 'user', senderRule: { id: 'r1', sender: 'news@bank.example', verdict: null, spamOverrules } })
    const wrapper = mountMenu('todo', 'do')

    await wrapper.get('button').trigger('click')
    await wrapper.findAll('[role="menuitemradio"]').find((item) => item.text().startsWith('Informational'))!.trigger('click')
    await flushPromises()

    expect(useComposeStore().notices.map((notice) => notice.text)).toEqual([text])
    wrapper.unmount()
  })

  it('says nothing more for a sender whose mail the rule takes', async () => {
    classify.mockResolvedValue({ threadId: 't1', smartInboxId: 'info', verdict: 'know', reason: 'user', senderRule: { id: 'r1', sender: 'anna@partner.example', verdict: null, spamOverrules: null } })
    const wrapper = mountMenu('todo', 'do')

    await wrapper.get('button').trigger('click')
    await wrapper.findAll('[role="menuitemradio"]').find((item) => item.text().startsWith('Informational'))!.trigger('click')
    await flushPromises()

    expect(useComposeStore().notices).toEqual([])
    wrapper.unmount()
  })
})
