import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

type Listener = (event: { data?: { json(): unknown }; waitUntil(promise: Promise<unknown>): void }) => void

/** The push worker (public/push-worker.js) in a fake service worker scope. */
async function worker(owner: string | null) {
  const listeners = new Map<string, Listener>()
  const shown: { title: string; options: Record<string, unknown> }[] = []
  const unsubscribe = vi.fn<() => Promise<boolean>>(async () => true)
  vi.stubGlobal('self', {
    location: { origin: 'https://app.example.test' },
    addEventListener: (type: string, listener: Listener) => listeners.set(type, listener),
    registration: {
      showNotification: async (title: string, options: Record<string, unknown>) => void shown.push({ title, options }),
      pushManager: { getSubscription: async () => ({ unsubscribe }) },
    },
  })
  vi.stubGlobal('caches', { open: async () => ({ match: async () => (owner === null ? undefined : new Response(owner)) }) })
  vi.resetModules()
  await import('../../../public/push-worker.js')

  async function push(payload: unknown): Promise<void> {
    const waiting: Promise<unknown>[] = []
    listeners.get('push')!({ data: { json: () => payload }, waitUntil: (promise) => waiting.push(promise) })
    await Promise.all(waiting)
  }
  return { push, shown, unsubscribe }
}

const mail = { id: 'n1', kind: 'mail', title: 'Anna', body: 'Budget review', path: '/thread/t1', silent: false, userId: 'kim' }

describe('the push worker', () => {
  beforeEach(() => vi.unstubAllGlobals())
  afterEach(() => vi.unstubAllGlobals())

  it('shows a push for the user who turned notifications on here, opening its conversation', async () => {
    const { push, shown, unsubscribe } = await worker('kim')

    await push(mail)

    expect(shown).toEqual([{ title: 'Anna', options: { body: 'Budget review', tag: 'n1', silent: false, data: { path: '/thread/t1' } } }])
    expect(unsubscribe).not.toHaveBeenCalled()
  })

  it('shows nothing of a push for someone else, and stops this browser getting pushes', async () => {
    const { push, shown, unsubscribe } = await worker('lisa')

    await push(mail)

    expect(shown.map((notification) => notification.title)).toEqual(['Tresso'])
    expect(JSON.stringify(shown)).not.toContain('Budget')
    expect(unsubscribe).toHaveBeenCalled()
  })

  it('shows nothing of a push that does not say whom it is for', async () => {
    const { push, shown, unsubscribe } = await worker('kim')

    await push({ ...mail, userId: undefined })

    expect(shown.map((notification) => notification.title)).toEqual(['Tresso'])
    expect(unsubscribe).toHaveBeenCalled()
  })
})
