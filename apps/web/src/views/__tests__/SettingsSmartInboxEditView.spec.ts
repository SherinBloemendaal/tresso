import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import type { SmartInbox, SyncedSmartInbox } from '@tresso/core'
import { useMailStore } from '@/stores/mail'
import SettingsSmartInboxEditView from '../SettingsSmartInboxEditView.vue'

function smartInbox(id: string, builtin: SyncedSmartInbox['builtin'], extra: Partial<SyncedSmartInbox> = {}): SyncedSmartInbox {
  return { id, builtin, name: id, instructions: '', includeSenders: [], excludeSenders: [], verdict: 'know', autoCreateTodo: false, position: 0, ...extra }
}

/** Form validation runs asynchronously; let it and the requests it starts finish. */
async function settle(): Promise<void> {
  for (let i = 0; i < 5; i++) {
    await flushPromises()
    await new Promise((resolve) => setTimeout(resolve, 0))
  }
}

describe('SettingsSmartInboxEditView', () => {
  const requests: { method: string; path: string; body: unknown }[] = []
  let router: Router

  beforeEach(() => {
    sessionStorage.setItem('auth.tokens', JSON.stringify({ accessToken: 'token', refreshToken: null, expiresAt: Date.now() + 3_600_000 }))
    setActivePinia(createPinia())
    requests.length = 0
    const mail = useMailStore()
    mail.ready = true
    mail.smartInboxes = [
      smartInbox('todo', 'todo', { name: 'To do', verdict: 'do' }),
      smartInbox('invoices', null, { name: 'Invoices', verdict: 'do', includeSenders: ['@supplier.example'], instructions: 'Bills' }),
      smartInbox('newsletters', null, { name: 'Newsletters', verdict: 'know', batchDelivery: true }),
    ]
    vi.spyOn(mail, 'syncNow').mockResolvedValue()
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = new URL(String(input))
        const method = init?.method ?? 'GET'
        if (url.pathname === '/api/v1/batch-delivery') {
          return new Response(JSON.stringify({ times: ['08:00', '12:00', '17:00'], timeZone: 'Europe/Amsterdam', configured: true, nextBatchAt: '2026-10-20T10:00:00Z' }))
        }
        const body = init?.body ? (JSON.parse(String(init.body)) as Partial<SmartInbox>) : undefined
        requests.push({ method, path: url.pathname, body })
        if (method === 'DELETE') {
          return new Response(null, { status: 204 })
        }
        if (body?.name === 'Taken') {
          return new Response(JSON.stringify({ detail: 'name: You already have a smart inbox with this name.', violations: [{ propertyPath: 'name', message: 'You already have a smart inbox with this name.' }] }), { status: 422 })
        }
        return new Response(JSON.stringify(smartInbox('saved', null, body)), { status: method === 'POST' ? 201 : 200 })
      }),
    )
    router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/settings/smart-inboxes', name: 'settings-smart-inboxes', component: { template: '<p>list</p>' } },
        { path: '/settings/smart-inboxes/:smartInboxId', name: 'settings-smart-inbox', component: SettingsSmartInboxEditView },
        { path: '/settings/batches', name: 'settings-batches', component: { template: '<p>batches</p>' } },
      ],
    })
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
    sessionStorage.clear()
  })

  async function mountFor(id: string) {
    await router.push({ name: 'settings-smart-inbox', params: { smartInboxId: id } })
    await router.isReady()
    const wrapper = mount(SettingsSmartInboxEditView, { global: { plugins: [router] } })
    await settle()
    return wrapper
  }

  it('marks the required fields from the schema', async () => {
    const wrapper = await mountFor('new')

    const required = wrapper.findAll('.field-label').filter((label) => label.find('.required').exists())
    expect(required.map((label) => label.text().replace(/\s*\*\s*\(required\)$/, ''))).toEqual(['Name', 'Verdict for matching mail'])
  })

  it('creates a smart inbox with its lists and verdict', async () => {
    const wrapper = await mountFor('new')

    await wrapper.get('input[maxlength="80"]').setValue('Partners')
    const include = wrapper.get('#include-senders')
    await include.setValue('@Partner.example, anna@else.example')
    await include.trigger('keydown', { key: 'Enter' })
    await wrapper.get('input[value="delegate"]').setValue(true)
    await wrapper.get('form').trigger('submit')
    await settle()

    expect(requests).toEqual([
      {
        method: 'POST',
        path: '/api/v1/smart-inboxes',
        body: { name: 'Partners', instructions: '', includeSenders: ['@partner.example', 'anna@else.example'], excludeSenders: [], verdict: 'delegate', autoCreateTodo: false, batchDelivery: false },
      },
    ])
    expect(router.currentRoute.value.name).toBe('settings-smart-inboxes')
  })

  it('refuses an entry that is not an address or domain before asking the server', async () => {
    const wrapper = await mountFor('invoices')

    const exclude = wrapper.get('#exclude-senders')
    await exclude.setValue('supplier')
    await exclude.trigger('keydown', { key: 'Enter' })
    await wrapper.get('form').trigger('submit')
    await settle()

    expect(requests).toEqual([])
    expect(wrapper.text()).toContain('Use an address (anna@example.com) or a domain (@example.com).')
  })

  it('sends only what changed', async () => {
    const wrapper = await mountFor('invoices')

    expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe('Bills')
    await wrapper.get('input[value="know"]').setValue(true)
    await wrapper.get('form').trigger('submit')
    await settle()

    expect(requests).toEqual([{ method: 'PATCH', path: '/api/v1/smart-inboxes/invoices', body: { verdict: 'know' } }])
  })

  it('switches automatic todos on for a smart inbox with the Do verdict', async () => {
    const wrapper = await mountFor('invoices')

    await wrapper.get('#auto-create-todo').setValue(true)
    await wrapper.get('form').trigger('submit')
    await settle()

    expect(requests).toEqual([{ method: 'PATCH', path: '/api/v1/smart-inboxes/invoices', body: { autoCreateTodo: true } }])
  })

  it('switches automatic todos off with another verdict, and offers them only with Do', async () => {
    useMailStore().smartInboxes = [smartInbox('todo', 'todo', { name: 'To do', verdict: 'do', autoCreateTodo: true })]
    const wrapper = await mountFor('todo')
    expect((wrapper.get('#auto-create-todo').element as HTMLInputElement).checked).toBe(true)

    await wrapper.get('input[value="know"]').setValue(true)
    await settle()
    expect(wrapper.get('#auto-create-todo').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('Only available with the Do verdict.')
    await wrapper.get('form').trigger('submit')
    await settle()

    expect(requests).toEqual([{ method: 'PATCH', path: '/api/v1/smart-inboxes/todo', body: { verdict: 'know', autoCreateTodo: false } }])
  })

  it('turns batch delivery on and off with the rest of the form', async () => {
    const wrapper = await mountFor('invoices')

    const checkbox = wrapper.get<HTMLInputElement>('#batch-delivery')
    expect(checkbox.element.checked).toBe(false)
    expect(wrapper.get('label[for="batch-delivery"]').text()).toBe('Deliver in batches')
    expect(wrapper.get('#batch-delivery-help').text()).toContain('Holds matches until 08:00, 12:00 and 17:00.')
    await checkbox.setValue(true)
    await wrapper.get('form').trigger('submit')
    await settle()

    expect(requests).toEqual([{ method: 'PATCH', path: '/api/v1/smart-inboxes/invoices', body: { batchDelivery: true } }])

    wrapper.unmount()
    requests.length = 0
    const batched = await mountFor('newsletters')
    expect(batched.get<HTMLInputElement>('#batch-delivery').element.checked).toBe(true)
    await batched.get('#batch-delivery').setValue(false)
    await batched.get('form').trigger('submit')
    await settle()

    expect(requests).toEqual([{ method: 'PATCH', path: '/api/v1/smart-inboxes/newsletters', body: { batchDelivery: false } }])
  })

  it('shows what the server refused on the field', async () => {
    const wrapper = await mountFor('invoices')

    await wrapper.get('input[maxlength="80"]').setValue('Taken')
    await wrapper.get('form').trigger('submit')
    await settle()

    expect(wrapper.get('input[maxlength="80"]').attributes('aria-invalid')).toBe('true')
    expect(router.currentRoute.value.name).toBe('settings-smart-inbox')
  })

  it('deletes only the user\'s own smart inboxes', async () => {
    const builtIn = await mountFor('todo')
    expect(builtIn.text()).not.toContain('Delete smart inbox')
    builtIn.unmount()

    const own = await mountFor('invoices')
    await own.findAll('button').find((button) => button.text() === 'Delete smart inbox')!.trigger('click')
    await own.findAll('button.button-danger').find((button) => button.text() === 'Delete smart inbox')!.trigger('click')
    await settle()

    expect(requests).toEqual([{ method: 'DELETE', path: '/api/v1/smart-inboxes/invoices', body: undefined }])
  })
})
