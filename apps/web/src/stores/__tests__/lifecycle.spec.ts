import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { SqliteMailStore, type ChangePage, type MailStore } from '@tresso/core'
import { memorySql } from '@tresso/core/testing/sql'

/** Lets a test hold an open or a close until it says so. */
function gate() {
  let open!: () => void
  const passed = new Promise<void>((resolve) => {
    open = resolve
  })
  return { passed, open }
}

const copies = vi.hoisted(() => ({
  opened: [] as string[],
  closed: [] as string[],
  deleted: [] as string[],
  holdOpen: new Map<string, Promise<void>>(),
  holdClose: new Map<string, Promise<void>>(),
}))

vi.mock('@/mail/store', () => ({
  openMailStore: async (userId: string): Promise<MailStore> => {
    await copies.holdOpen.get(userId)
    const store = await SqliteMailStore.open(await memorySql())
    copies.opened.push(userId)
    const close = store.close.bind(store)
    store.close = async () => {
      await copies.holdClose.get(userId)
      await close()
      copies.closed.push(userId)
    }
    return store
  },
  deleteMailStore: async (userId: string) => {
    copies.deleted.push(userId)
  },
}))

const { useMailStore } = await import('../mail')

function page(since: number): ChangePage {
  return { cursor: since, hasMore: false, mailAccounts: [], folders: [], threads: [], messages: [], outgoingMessages: [], signatures: [], drafts: [], deleted: [] }
}

describe('the mail copy across sign-out and sign-in', () => {
  beforeEach(() => {
    sessionStorage.setItem('auth.tokens', JSON.stringify({ accessToken: 'token', refreshToken: null, expiresAt: Date.now() + 3_600_000 }))
    setActivePinia(createPinia())
    copies.opened = []
    copies.closed = []
    copies.deleted = []
    copies.holdOpen.clear()
    copies.holdClose.clear()
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => {
        const url = new URL(String(input))
        if (url.pathname === '/api/v1/sync/changes') {
          return new Response(JSON.stringify(page(Number(url.searchParams.get('since')))))
        }
        return new Response(JSON.stringify({ detail: 'unavailable' }), { status: 503 })
      }),
    )
  })

  afterEach(async () => {
    await useMailStore().stop()
    vi.unstubAllGlobals()
    sessionStorage.clear()
  })

  it('keeps the next user signed in when the previous copy finishes closing late, and removes their copy on their sign-out', async () => {
    const mail = useMailStore()
    await mail.start('anna')
    const closing = gate()
    copies.holdClose.set('anna', closing.passed)

    const forgetting = mail.forget()
    await mail.start('ben')
    closing.open()
    await forgetting

    expect(copies.deleted).toEqual(['anna'])
    expect(mail.ready).toBe(true)
    await mail.forget()
    expect(copies.deleted).toEqual(['anna', 'ben'])
  })

  it('closes a copy whose opening was overtaken by another sign-in', async () => {
    const mail = useMailStore()
    const opening = gate()
    copies.holdOpen.set('anna', opening.passed)

    const first = mail.start('anna')
    await mail.start('ben')
    opening.open()
    await first

    expect(copies.opened.sort()).toEqual(['anna', 'ben'])
    expect(copies.closed).toEqual(['anna'])
    expect(mail.ready).toBe(true)
    await mail.forget()
    expect(copies.deleted).toEqual(['ben'])
  })
})
