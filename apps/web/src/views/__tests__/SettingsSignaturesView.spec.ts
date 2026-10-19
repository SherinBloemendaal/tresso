import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type { Signature, SyncedMailAccount, SyncedSignature } from '@tresso/core'
import { useMailStore } from '@/stores/mail'
import SettingsSignaturesView from '../SettingsSignaturesView.vue'

const UPDATED_AT = '2026-10-19T08:00:00+00:00'

function account(id: string, displayName: string, defaultSignatureId: string | null): SyncedMailAccount {
  return { id, emailAddress: `${id}@company.example`, displayName, provider: 'imap', status: 'active', statusDetail: null, lastSyncedAt: null, defaultSignatureId, calendarStatus: 'unknown' as const, calendarStatusDetail: null }
}

function signature(id: string, name: string, html: string): SyncedSignature {
  return { id, name, html, updatedAt: UPDATED_AT }
}

/** Form validation runs asynchronously; let it and the requests it starts finish. */
async function settle(): Promise<void> {
  for (let i = 0; i < 5; i++) {
    await flushPromises()
    await new Promise((resolve) => setTimeout(resolve, 0))
  }
}

describe('SettingsSignaturesView', () => {
  const requests: { method: string; path: string; body: unknown }[] = []
  let mail: ReturnType<typeof useMailStore>
  /** What the next pull brings into the synced copy. */
  let afterSync: () => void

  beforeEach(() => {
    sessionStorage.setItem('auth.tokens', JSON.stringify({ accessToken: 'token', refreshToken: null, expiresAt: Date.now() + 3_600_000 }))
    setActivePinia(createPinia())
    requests.length = 0
    afterSync = () => {}
    mail = useMailStore()
    mail.ready = true
    mail.signatures = [signature('sig-support', 'Support', '<p>Support team</p>'), signature('sig-work', 'Work', '<p>Kim</p>')]
    mail.accounts = [account('work', 'Work', 'sig-work'), account('support', 'Support', 'sig-support'), account('home', 'Personal', null)]
    vi.spyOn(mail, 'syncNow').mockImplementation(async () => afterSync())
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = new URL(String(input))
        const method = init?.method ?? 'GET'
        const body = init?.body ? (JSON.parse(String(init.body)) as Partial<Signature>) : undefined
        requests.push({ method, path: url.pathname, body })
        if (method === 'DELETE') {
          return new Response(null, { status: 204 })
        }
        if (method === 'POST' && body?.name === 'Rejected') {
          return new Response(JSON.stringify({ detail: 'name: This name is not allowed.', violations: [{ propertyPath: 'name', message: 'This name is not allowed.' }] }), { status: 422 })
        }
        const saved: Signature = { id: method === 'POST' ? 'sig-new' : url.pathname.split('/').pop()!, name: '', html: '', defaultFor: [], updatedAt: UPDATED_AT, ...body }
        return new Response(JSON.stringify(saved), { status: method === 'POST' ? 201 : 200 })
      }),
    )
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
    sessionStorage.clear()
  })

  function mountView() {
    return mount(SettingsSignaturesView)
  }

  function textarea(wrapper: ReturnType<typeof mountView>): HTMLTextAreaElement {
    return wrapper.get('textarea').element as HTMLTextAreaElement
  }

  it('lists signatures with their defaults and previews the first in locked-down frames', () => {
    const wrapper = mountView()

    const options = wrapper.findAll('[role="option"]')
    expect(options.map((o) => o.text())).toEqual(['SupportDefault for 1 account', 'WorkDefault for 1 account'])
    expect(options[0]!.attributes('aria-selected')).toBe('true')
    expect((wrapper.get('input:not([type])').element as HTMLInputElement).value).toBe('Support')
    expect(textarea(wrapper).value).toBe('<p>Support team</p>')

    const frames = wrapper.findAll('iframe')
    expect(frames).toHaveLength(2)
    for (const frame of frames) {
      expect(frame.attributes('sandbox')).toBe('allow-same-origin')
      expect(frame.attributes('srcdoc')).toContain('<body><p>Support team</p></body>')
    }
  })

  it('updates the preview and size while typing, and saves only what changed', async () => {
    const wrapper = mountView()
    await wrapper.findAll('[role="option"]')[1]!.trigger('click')

    await wrapper.get('textarea').setValue('<p onclick="x()">Kim Visser</p>')
    expect(wrapper.get('iframe').attributes('srcdoc')).toContain('<p onclick="x()">Kim Visser</p>')
    expect(wrapper.text()).toContain('31 bytes')

    afterSync = () => {
      mail.signatures = [mail.signatures[0]!, signature('sig-work', 'Work', '<p>Kim Visser</p>')]
    }
    await wrapper.get('form').trigger('submit')
    await settle()

    expect(requests).toEqual([{ method: 'PATCH', path: '/api/v1/signatures/sig-work', body: { html: '<p onclick="x()">Kim Visser</p>' } }])
    expect(textarea(wrapper).value).toBe('<p>Kim Visser</p>')
    expect(wrapper.get('[role="status"]').text()).toContain('Saved.')
  })

  it('says which signature a default replaces and saves the complete list', async () => {
    const wrapper = mountView()
    await wrapper.findAll('[role="option"]')[1]!.trigger('click')

    const labels = wrapper.findAll('.check')
    expect(labels.map((l) => l.text())).toEqual(['Work', 'Support(now Support)', 'Personal(now no signature)'])

    await labels[1]!.get('input').setValue(true)
    expect(wrapper.findAll('.check')[1]!.text()).toBe('Support')
    await labels[0]!.get('input').setValue(false)
    expect(wrapper.findAll('.check')[0]!.text()).toBe('Work(now no signature)')

    await wrapper.get('form').trigger('keydown', { key: 's', ctrlKey: true })
    await settle()

    expect(requests).toEqual([{ method: 'PATCH', path: '/api/v1/signatures/sig-work', body: { defaultFor: ['support'] } }])
  })

  it('creates a new signature and opens it once synced', async () => {
    const wrapper = mountView()
    await wrapper.get('button.new').trigger('click')
    expect(textarea(wrapper).value).toBe('')

    await wrapper.get('input:not([type])').setValue('Short reply')
    await wrapper.get('textarea').setValue('<p>K.</p>')
    await wrapper.findAll('.check')[2]!.get('input').setValue(true)
    afterSync = () => {
      mail.signatures = [...mail.signatures, signature('sig-new', 'Short reply', '<p>K.</p>')]
      mail.accounts = mail.accounts.map((a) => (a.id === 'home' ? { ...a, defaultSignatureId: 'sig-new' } : a))
    }
    await wrapper.get('form').trigger('submit')
    await settle()

    expect(requests).toEqual([{ method: 'POST', path: '/api/v1/signatures', body: { name: 'Short reply', html: '<p>K.</p>', defaultFor: ['home'] } }])
    const selected = wrapper.get('[role="option"][aria-selected="true"]')
    expect(selected.text()).toBe('Short replyDefault for 1 account')
  })

  it('asks for a name before saving', async () => {
    const wrapper = mountView()
    await wrapper.get('button.new').trigger('click')

    await wrapper.get('form').trigger('submit')
    await settle()

    expect(wrapper.find('.field .error').text()).toBe('Give the signature a name.')
    expect(requests.filter((r) => r.method !== 'GET')).toEqual([])
  })

  it('shows what the API rejected', async () => {
    const wrapper = mountView()
    await wrapper.get('button.new').trigger('click')
    await wrapper.get('.field input').setValue('Rejected')

    await wrapper.get('form').trigger('submit')
    await settle()

    expect(wrapper.get('[role="alert"]').text()).toBe('name: This name is not allowed.')
    expect(wrapper.find('.field .error').text()).toBe('This name is not allowed.')
    expect(mail.syncNow).not.toHaveBeenCalled()
  })


  it('deletes after confirmation and opens the next signature', async () => {
    const wrapper = mountView()

    await wrapper.get('button.button-danger').trigger('click')
    expect(wrapper.get('[role="alertdialog"]').text()).toContain('Delete Support?')
    afterSync = () => {
      mail.signatures = mail.signatures.filter((s) => s.id !== 'sig-support')
      mail.accounts = mail.accounts.map((a) => (a.defaultSignatureId === 'sig-support' ? { ...a, defaultSignatureId: null } : a))
    }
    await wrapper.get('[role="alertdialog"] button.button-danger').trigger('click')
    await settle()

    expect(requests).toEqual([{ method: 'DELETE', path: '/api/v1/signatures/sig-support', body: undefined }])
    expect(wrapper.findAll('[role="option"]').map((o) => o.text())).toEqual(['WorkDefault for 1 account'])
    expect(textarea(wrapper).value).toBe('<p>Kim</p>')
  })

  it('invites to create the first signature', () => {
    mail.signatures = []

    const wrapper = mountView()

    expect(wrapper.text()).toContain('No signatures yet.')
    expect(wrapper.find('form').exists()).toBe(false)
  })
})
