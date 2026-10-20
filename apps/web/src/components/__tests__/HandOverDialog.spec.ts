import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { HandOverError, type HandOverInput, type SyncedMessage } from '@tresso/core'
import { useHandOversStore } from '@/stores/handOvers'
import HandOverDialog from '../HandOverDialog.vue'

const message: SyncedMessage = {
  id: 'm1',
  mailAccountId: 'work',
  threadId: 't1',
  folderIds: ['inbox'],
  subject: 'Invoice question',
  from: { address: 'reservations@duinzicht.example', name: 'Hotel Duinzicht' },
  recipients: { to: [], cc: [], bcc: [], replyTo: [] },
  snippet: '',
  sentAt: null,
  receivedAt: '2026-10-19T16:40:00Z',
  flags: { seen: true, flagged: false, answered: false, draft: false },
  hasAttachments: false,
  smartInboxId: null,
  verdict: 'delegate',
  summary: null,
  calendarMethod: null,
}

/** Form validation runs asynchronously; let it finish. */
async function settle(): Promise<void> {
  for (let i = 0; i < 5; i++) {
    await flushPromises()
    await new Promise((resolve) => setTimeout(resolve, 0))
  }
}

describe('HandOverDialog', () => {
  let handOvers: ReturnType<typeof useHandOversStore>
  let handOver: ReturnType<typeof vi.fn<(message: SyncedMessage, input: HandOverInput) => Promise<void>>>

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] })
    // Monday 20 October 2026, morning: today, tomorrow and Friday are offered.
    vi.setSystemTime(new Date(2026, 9, 20, 9, 0))
    setActivePinia(createPinia())
    handOvers = useHandOversStore()
    handOver = vi.fn<(message: SyncedMessage, input: HandOverInput) => Promise<void>>(async () => {})
    handOvers.handOver = handOver
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  function mountDialog() {
    return mount(HandOverDialog, { props: { message }, attachTo: document.body })
  }

  async function chooseLisa(wrapper: ReturnType<typeof mountDialog>): Promise<void> {
    const input = wrapper.get('input[role="combobox"], .recipients input')
    await input.setValue('Lisa Bakker <lisa@company.example>')
    await input.trigger('keydown', { key: 'Enter' })
  }

  it('says what is handed over and offers today, tomorrow, Friday and a date of choice', () => {
    const wrapper = mountDialog()

    expect(wrapper.text()).toContain('Invoice question, from Hotel Duinzicht')
    expect(wrapper.findAll('.choice-label').map((label) => label.text())).toEqual(['Today', 'Tomorrow', 'Friday', 'Pick a date'])
    wrapper.unmount()
  })

  it('asks who to hand it over to', async () => {
    const wrapper = mountDialog()

    await wrapper.get('form').trigger('submit')
    await settle()

    expect(wrapper.text()).toContain('Choose who to hand it over to.')
    expect(handOver).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('hands it over with the note, due at the end of the chosen day', async () => {
    const wrapper = mountDialog()
    await chooseLisa(wrapper)
    await wrapper.get('textarea').setValue('Can you check the double charge?')
    await wrapper.findAll('input[name="due"]')[2]!.setValue(true)
    await settle()

    expect(wrapper.get('button[type="submit"]').text()).toBe('Hand over to Lisa')
    await wrapper.get('form').trigger('submit')
    await settle()

    expect(handOver).toHaveBeenCalledTimes(1)
    const [handed, input] = handOver.mock.calls[0]!
    expect(handed.id).toBe('m1')
    expect(input.delegate).toEqual({ address: 'lisa@company.example', name: 'Lisa Bakker' })
    expect(input.note).toBe('Can you check the double charge?')
    expect(input.dueAt).toEqual(new Date(2026, 9, 23, 17, 0))
    expect(input.dueLabel).toContain('23')
    wrapper.unmount()
  })

  it('needs a day ahead when the user picks one', async () => {
    const wrapper = mountDialog()
    await chooseLisa(wrapper)
    await wrapper.findAll('input[name="due"]')[3]!.setValue(true)
    await settle()
    await wrapper.get('input[type="date"]').setValue('2026-10-19')

    await wrapper.get('form').trigger('submit')
    await settle()

    expect(wrapper.text()).toContain('Pick a day that is still ahead.')
    expect(handOver).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('shows why handing over failed and keeps the form', async () => {
    handOver.mockRejectedValueOnce(new HandOverError('The forward could not be sent.'))
    const wrapper = mountDialog()
    await chooseLisa(wrapper)

    await wrapper.get('form').trigger('submit')
    await settle()

    expect(wrapper.get('[role="alert"]').text()).toBe('The forward could not be sent.')
    expect(wrapper.find('textarea').exists()).toBe(true)
    wrapper.unmount()
  })

  it('closes on Escape', async () => {
    const close = vi.spyOn(handOvers, 'close')
    const wrapper = mountDialog()

    await wrapper.get('[role="dialog"]').trigger('keydown', { key: 'Escape' })

    expect(close).toHaveBeenCalled()
    wrapper.unmount()
  })
})
