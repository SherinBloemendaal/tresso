import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { BulkActionState } from '@tresso/core'
import { useComposeStore } from '@/stores/compose'
import { useMailStore } from '@/stores/mail'
import { useBulkStore } from '../bulk'

function state(override: Partial<BulkActionState>): BulkActionState {
  return {
    id: 'b1',
    action: 'archive',
    folderId: null,
    scope: { in: 'smartInbox', smartInboxId: 's1', mailAccountId: null, sender: null, before: null, largerThan: null },
    status: 'running',
    conversations: 0,
    changed: 0,
    unrestorable: 0,
    restored: 0,
    canUndo: false,
    error: null,
    createdAt: '2026-10-20T10:00:00+00:00',
    finishedAt: null,
    ...override,
  }
}

function last<T>(items: T[]): T | undefined {
  return items[items.length - 1]
}

describe('bulk store', () => {
  const requests: { method: string; path: string; body: unknown }[] = []
  let replies: Record<string, BulkActionState[]>
  let refuse: string | null

  beforeEach(() => {
    vi.useFakeTimers()
    sessionStorage.setItem('auth.tokens', JSON.stringify({ accessToken: 'token', refreshToken: null, expiresAt: Date.now() + 3_600_000 }))
    setActivePinia(createPinia())
    requests.length = 0
    refuse = null
    replies = {}
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = new URL(String(input))
        const method = init?.method ?? 'GET'
        requests.push({ method, path: `${url.pathname}${url.search}`, body: init?.body ? JSON.parse(String(init.body)) : undefined })
        if (refuse !== null) {
          return new Response(JSON.stringify({ detail: refuse }), { status: 422 })
        }
        if (url.pathname === '/api/v1/bulk-selection') {
          return new Response(JSON.stringify({ conversations: 12, messages: 14, mailAccountIds: ['work'] }))
        }
        const next = replies[`${method} ${url.pathname}`]?.shift()
        return new Response(JSON.stringify(next), { status: method === 'POST' ? 202 : 200 })
      }),
    )
    vi.spyOn(useMailStore(), 'syncNow').mockResolvedValue()
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
    sessionStorage.clear()
  })

  it('counts a scope before acting on it', async () => {
    expect(await useBulkStore().count({ in: 'smartInbox', smartInboxId: 's1', olderThanDays: 30 })).toEqual({ conversations: 12, messages: 14, mailAccountIds: ['work'] })
    expect(requests[0]?.path).toBe('/api/v1/bulk-selection?in=smartInbox&smartInboxId=s1&olderThanDays=30')
  })

  it('follows an action until it is done, then pulls and offers the undo', async () => {
    replies['POST /api/v1/bulk-actions'] = [state({})]
    replies['GET /api/v1/bulk-actions/b1'] = [state({ conversations: 200 }), state({ status: 'done', conversations: 312, changed: 330, canUndo: true })]
    const bulk = useBulkStore()
    const compose = useComposeStore()

    const done = bulk.start({ in: 'smartInbox', smartInboxId: 's1', action: 'archive' })
    await vi.advanceTimersByTimeAsync(0)
    expect(bulk.progress).toBe('Archiving: 0 conversations done')
    await vi.advanceTimersByTimeAsync(500)
    expect(bulk.progress).toBe('Archiving: 200 conversations done')
    await vi.advanceTimersByTimeAsync(1000)

    expect((await done)?.status).toBe('done')
    expect(bulk.running).toBeNull()
    expect(useMailStore().syncNow).toHaveBeenCalledOnce()
    expect(requests[0]).toEqual({ method: 'POST', path: '/api/v1/bulk-actions', body: { in: 'smartInbox', smartInboxId: 's1', action: 'archive' } })
    const notice = last(compose.notices)
    expect(notice?.text).toBe('Archived: 312 conversations.')
    expect(notice?.action?.label).toBe('Undo')

    replies['POST /api/v1/bulk-actions/b1/undo'] = [state({ status: 'undoing' })]
    replies['GET /api/v1/bulk-actions/b1'] = [state({ status: 'undone', restored: 330 })]
    notice?.action?.run()
    await vi.advanceTimersByTimeAsync(600)
    expect(requests.map((request) => `${request.method} ${request.path}`).slice(-2)).toEqual(['POST /api/v1/bulk-actions/b1/undo', 'GET /api/v1/bulk-actions/b1'])
    expect(last(compose.notices)?.text).toBe('Put back 330 messages.')
  })

  it('offers no undo when nothing could be put back', async () => {
    replies['POST /api/v1/bulk-actions'] = [state({ status: 'done', conversations: 2, changed: 2, unrestorable: 2, canUndo: true })]

    await useBulkStore().start({ in: 'inbox', action: 'trash' })

    expect(last(useComposeStore().notices)?.action).toBeUndefined()
  })

  it('starts once however quickly it is asked twice', async () => {
    replies['POST /api/v1/bulk-actions'] = [state({ status: 'done' })]
    const bulk = useBulkStore()

    const first = bulk.start({ in: 'inbox', action: 'archive' })
    expect(bulk.busy).toBe(true)
    expect(await bulk.start({ in: 'inbox', action: 'archive' })).toBeNull()
    await first

    expect(requests.filter((request) => request.method === 'POST')).toHaveLength(1)
    expect(bulk.busy).toBe(false)
  })

  it('runs one at a time', async () => {
    replies['POST /api/v1/bulk-actions'] = [state({})]
    replies['GET /api/v1/bulk-actions/b1'] = [state({ status: 'done' })]
    const bulk = useBulkStore()

    const first = bulk.start({ in: 'inbox', action: 'markRead' })
    await vi.advanceTimersByTimeAsync(0)
    expect(await bulk.start({ in: 'inbox', action: 'archive' })).toBeNull()
    expect(last(useComposeStore().notices)?.text).toBe('Another bulk action is still under way. Try again once it is done.')
    await vi.advanceTimersByTimeAsync(500)
    await first
    expect(requests.filter((request) => request.method === 'POST')).toHaveLength(1)
  })

  it('says why the server refused it', async () => {
    refuse = 'action: kim@home.example has no Archive folder.'

    expect(await useBulkStore().start({ in: 'inbox', action: 'archive' })).toBeNull()

    expect(last(useComposeStore().notices)).toMatchObject({ text: 'action: kim@home.example has no Archive folder.', tone: 'error' })
    expect(useMailStore().syncNow).not.toHaveBeenCalled()
  })
})
