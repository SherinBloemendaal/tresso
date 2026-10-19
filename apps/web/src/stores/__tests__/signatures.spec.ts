import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { Signature, SyncedMailAccount } from '@tresso/core'
import { ApiError } from '@/api/errors'
import { useMailStore } from '@/stores/mail'
import { useSignaturesStore } from '../signatures'

function account(id: string, defaultSignatureId: string | null): SyncedMailAccount {
  return { id, emailAddress: `${id}@company.example`, displayName: id, provider: 'imap', status: 'active', statusDetail: null, lastSyncedAt: null, defaultSignatureId, calendarStatus: 'unknown' as const, calendarStatusDetail: null }
}

const stored: Signature = { id: 'sig-1', name: 'Work', html: '<p>Kim</p>', defaultFor: ['work'], updatedAt: '2026-10-19T08:00:00+00:00' }

describe('signatures store', () => {
  const requests: { method: string; path: string; contentType: string | null; body: unknown }[] = []
  let respond: (method: string, path: string) => Response

  beforeEach(() => {
    sessionStorage.setItem('auth.tokens', JSON.stringify({ accessToken: 'token', refreshToken: null, expiresAt: Date.now() + 3_600_000 }))
    setActivePinia(createPinia())
    requests.length = 0
    respond = (method) =>
      method === 'DELETE' ? new Response(null, { status: 204 }) : new Response(JSON.stringify(stored), { status: method === 'POST' ? 201 : 200 })
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = new URL(String(input))
        const method = init?.method ?? 'GET'
        const contentType = new Headers(init?.headers).get('Content-Type')
        requests.push({ method, path: url.pathname, contentType, body: init?.body ? JSON.parse(String(init.body)) : undefined })
        return respond(method, url.pathname)
      }),
    )
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    sessionStorage.clear()
  })

  it('reads signatures and defaults from the synced copy', () => {
    const mail = useMailStore()
    mail.signatures = [{ id: 'sig-1', name: 'Work', html: '<p>Kim</p>', updatedAt: stored.updatedAt }]
    mail.accounts = [account('work', 'sig-1'), account('home', 'sig-1'), account('support', null)]
    const store = useSignaturesStore()

    expect(store.signatures.map((s) => s.name)).toEqual(['Work'])
    expect(store.defaultFor('sig-1')).toEqual(['work', 'home'])
    expect(store.defaultFor('sig-2')).toEqual([])
  })

  it('writes through the API and pulls the synced copy right after', async () => {
    const mail = useMailStore()
    const syncNow = vi.spyOn(mail, 'syncNow').mockResolvedValue()
    const store = useSignaturesStore()

    expect(await store.create({ name: 'Work', html: '<p>Kim</p>', defaultFor: ['work'] })).toEqual(stored)
    await store.update('sig-1', { html: '<p>Kim Visser</p>' })
    await store.remove('sig-1')

    expect(requests).toEqual([
      { method: 'POST', path: '/api/v1/signatures', contentType: 'application/json', body: { name: 'Work', html: '<p>Kim</p>', defaultFor: ['work'] } },
      { method: 'PATCH', path: '/api/v1/signatures/sig-1', contentType: 'application/merge-patch+json', body: { html: '<p>Kim Visser</p>' } },
      { method: 'DELETE', path: '/api/v1/signatures/sig-1', contentType: null, body: undefined },
    ])
    expect(syncNow).toHaveBeenCalledTimes(3)
  })

  it('does not pull when the API refuses the write', async () => {
    const mail = useMailStore()
    const syncNow = vi.spyOn(mail, 'syncNow').mockResolvedValue()
    respond = () =>
      new Response(JSON.stringify({ detail: 'name: This value should not be blank.', violations: [{ propertyPath: 'name', message: 'This value should not be blank.' }] }), {
        status: 422,
      })

    await expect(useSignaturesStore().create({ name: ' ', html: '' })).rejects.toBeInstanceOf(ApiError)
    expect(syncNow).not.toHaveBeenCalled()
  })
})
