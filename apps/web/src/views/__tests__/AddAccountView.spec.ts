import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { MailAccount, SyncedMailAccount } from '@tresso/core'
import { useMailStore } from '@/stores/mail'
import AddAccountView from '../AddAccountView.vue'

const created: MailAccount = {
  id: 'acc-1',
  emailAddress: 'kim@fastmail.com',
  displayName: 'Kim',
  provider: 'imap',
  status: 'active',
  statusDetail: null,
  username: 'kim@fastmail.com',
  imap: { host: 'imap.fastmail.com', port: 993, security: 'tls' },
  smtp: { host: 'smtp.fastmail.com', port: 465, security: 'tls' },
  lastSyncedAt: null,
  createdAt: '2026-10-19T08:00:00+00:00',
  defaultSignatureId: null,
}

function synced(account: MailAccount): SyncedMailAccount {
  const { id, emailAddress, displayName, provider, status, statusDetail, lastSyncedAt, defaultSignatureId } = account
  return { id, emailAddress, displayName, provider, status, statusDetail, lastSyncedAt, defaultSignatureId }
}

/** Form validation runs asynchronously; let it and the requests it starts finish. */
async function settle(): Promise<void> {
  for (let i = 0; i < 5; i++) {
    await flushPromises()
    await new Promise((resolve) => setTimeout(resolve, 0))
  }
}

describe('AddAccountView', () => {
  let server: MailAccount
  const requests: { method: string; path: string; body: unknown }[] = []

  beforeEach(() => {
    sessionStorage.setItem('auth.tokens', JSON.stringify({ accessToken: 'token', refreshToken: null, expiresAt: Date.now() + 3_600_000 }))
    setActivePinia(createPinia())
    server = { ...created }
    requests.length = 0
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = new URL(String(input))
        const method = init?.method ?? 'GET'
        requests.push({ method, path: url.pathname + url.search, body: init?.body ? JSON.parse(String(init.body)) : undefined })
        const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
        if (url.pathname === '/api/v1/mail-server-settings') {
          return json({
            emailAddress: 'kim@fastmail.com',
            found: true,
            source: 'ispdb',
            username: 'kim@fastmail.com',
            imap: created.imap,
            smtp: created.smtp,
            authentication: ['password'],
          })
        }
        if (url.pathname === '/api/v1/mail-accounts' && method === 'POST') {
          return json(server, 201)
        }
        if (url.pathname === '/api/v1/mail-accounts/acc-1' && method === 'PATCH') {
          server = { ...server, status: 'active', statusDetail: null }
          return json(server)
        }
        if (url.pathname === '/api/v1/mail-accounts/acc-1') {
          return json(server)
        }
        return json({ detail: 'Unexpected request' }, 500)
      }),
    )
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    sessionStorage.clear()
  })

  async function mountView() {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/settings/accounts', name: 'settings-accounts', component: { template: '<div />' } },
        { path: '/settings/accounts/new', name: 'settings-accounts-new', component: AddAccountView },
        { path: '/accounts/:accountId', name: 'account', component: { template: '<div />' } },
      ],
    })
    await router.push('/settings/accounts/new')
    await router.isReady()

    return mount(AddAccountView, { global: { plugins: [router] } })
  }

  it('fills in detected settings, reports a failed sign-in and connects after the fix', async () => {
    const wrapper = await mountView()
    const mail = useMailStore()

    await wrapper.get('input[type="email"]').setValue('kim@fastmail.com')
    await wrapper.findAll('input')[1]!.setValue('Kim')
    await wrapper.get('form').trigger('submit')
    await settle()

    expect(wrapper.text()).toContain('We filled in the settings we found for fastmail.com')
    expect((wrapper.find('input[autocomplete="off"]').element as HTMLInputElement).value).toBe('kim@fastmail.com')
    expect(wrapper.findAll('input').some((input) => (input.element as HTMLInputElement).value === 'imap.fastmail.com')).toBe(true)

    await wrapper.get('input[type="password"]').setValue('wrong-password')
    await wrapper.get('form').trigger('submit')
    await settle()

    expect(requests.find((r) => r.method === 'POST')?.body).toMatchObject({ emailAddress: 'kim@fastmail.com', displayName: 'Kim', password: 'wrong-password', imap: created.imap })
    expect(wrapper.text()).toContain('Signing in and fetching your folders')

    server = { ...server, status: 'auth_failed', statusDetail: 'Invalid credentials' }
    mail.accounts = [synced(server)]
    await settle()
    expect(wrapper.text()).toContain('Signing in failed.')
    expect(wrapper.text()).toContain('The server said: Invalid credentials')

    await wrapper.get('button.button-primary').trigger('click')
    await wrapper.get('input[type="password"]').setValue('right-password')
    await wrapper.get('form').trigger('submit')
    await settle()

    expect(requests.filter((r) => r.method === 'POST')).toHaveLength(1)
    expect(requests.find((r) => r.method === 'PATCH')?.body).toMatchObject({ password: 'right-password' })
    expect(wrapper.text()).toContain('Signing in and fetching your folders')

    server = { ...server, lastSyncedAt: '2026-10-19T08:01:00+00:00' }
    mail.accounts = [synced(server)]
    await settle()
    expect(wrapper.text()).toContain('Connected. Your mail is arriving')
  })
})
