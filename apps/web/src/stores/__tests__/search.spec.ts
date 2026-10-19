import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { SearchResults } from '@tresso/core'
import { useSearchStore } from '../search'

function results(query: string): SearchResults {
  return {
    query,
    page: 1,
    perPage: 20,
    totalHits: 1,
    totalPages: 1,
    messages: [
      {
        id: query,
        threadId: `thread-${query}`,
        accountId: 'work',
        folderIds: [],
        fromAddress: 'anna@finance.example',
        fromName: null,
        receivedAt: '2026-10-19T09:12:00+00:00',
        seen: true,
        flagged: false,
        hasAttachments: false,
        subject: [{ text: query, match: true }],
        from: [{ text: 'anna@finance.example', match: false }],
        preview: [],
        attachments: [],
      },
    ],
  }
}

describe('search store', () => {
  const pending = new Map<string, (response: Response) => void>()

  beforeEach(() => {
    sessionStorage.setItem('auth.tokens', JSON.stringify({ accessToken: 'token', refreshToken: null, expiresAt: Date.now() + 3_600_000 }))
    setActivePinia(createPinia())
    pending.clear()
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => new Promise<Response>((resolve) => pending.set(new URL(String(input)).searchParams.get('q') ?? '', resolve))),
    )
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    sessionStorage.clear()
  })

  function answer(q: string): void {
    pending.get(q)?.(new Response(JSON.stringify(results(q)), { status: 200, headers: { 'Content-Type': 'application/json' } }))
  }

  it('keeps the newest search when an older one answers late', async () => {
    const search = useSearchStore()

    const first = search.run('budget')
    await vi.waitFor(() => expect(pending.has('budget')).toBe(true))
    const second = search.run('invoice')
    await vi.waitFor(() => expect(pending.has('invoice')).toBe(true))
    answer('invoice')
    await second
    answer('budget')
    await first

    expect(search.query).toBe('invoice')
    expect(search.hits.map((hit) => hit.id)).toEqual(['invoice'])
    expect(search.loading).toBe(false)
  })

  it('clears the results for an empty search without asking the API', async () => {
    const search = useSearchStore()

    await search.run('   ')

    expect(pending.size).toBe(0)
    expect(search.hits).toEqual([])
    expect(search.query).toBe('')
  })
})
