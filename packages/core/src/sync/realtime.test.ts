import { afterEach, describe, expect, it, vi } from 'vitest'
import { RealtimeClient } from './realtime'
import type { RealtimeAccess } from './types'

/** A hub response whose event stream the test writes to. */
function stream(): { response: Response; send(text: string): void; close(): void } {
  let controller!: ReadableStreamDefaultController<Uint8Array>
  const body = new ReadableStream<Uint8Array>({ start: (c) => (controller = c) })
  const encoder = new TextEncoder()

  return {
    response: new Response(body, { status: 200, headers: { 'Content-Type': 'text/event-stream' } }),
    send: (text) => controller.enqueue(encoder.encode(text)),
    close: () => controller.close(),
  }
}

function access(token: string, expiresInMs = 3_600_000): RealtimeAccess {
  return {
    hubUrl: 'https://app.example/.well-known/mercure',
    topic: 'urn:tresso:user:u1:changes',
    token,
    expiresAt: new Date(Date.now() + expiresInMs).toISOString(),
  }
}

const tick = () => new Promise((resolve) => setTimeout(resolve, 0))

async function until(condition: () => boolean): Promise<void> {
  for (let i = 0; i < 200 && !condition(); i++) {
    await tick()
  }
  expect(condition()).toBe(true)
}

describe('RealtimeClient', () => {
  let client: RealtimeClient | null = null
  afterEach(async () => {
    await client?.stop()
    client = null
  })

  it('subscribes with the token as bearer and reports cursors', async () => {
    const hub = stream()
    const fetch = vi.fn(async (_url: URL | RequestInfo, _init?: RequestInit) => hub.response)
    const cursors: number[] = []
    const onConnected = vi.fn()
    client = new RealtimeClient({ fetchAccess: async () => access('t1'), onCursor: (c) => cursors.push(c), onConnected, fetch })

    client.start()
    await until(() => onConnected.mock.calls.length === 1)
    hub.send(':\n\nevent: changes\ndata: {"cursor":7}\n\n')
    hub.send('event: other\ndata: {"cursor":99}\n\nevent: changes\ndata: not json\n\nevent: changes\ndata: {"cursor":-1}\n\n')
    hub.send('event: changes\ndata: {"cursor":8}\n\n')
    await until(() => cursors.length === 2)

    expect(cursors).toEqual([7, 8])
    const [url, init] = fetch.mock.calls[0]!
    expect(String(url)).toBe('https://app.example/.well-known/mercure?match=urn%3Atresso%3Auser%3Au1%3Achanges')
    expect(new Headers(init?.headers).get('Authorization')).toBe('Bearer t1')
  })

  it('retries with a fresh token after a refusal', async () => {
    const hub = stream()
    const fetch = vi
      .fn<(url: URL | RequestInfo, init?: RequestInit) => Promise<Response>>()
      .mockResolvedValueOnce(new Response(null, { status: 401 }))
      .mockResolvedValue(hub.response)
    const fetchAccess = vi.fn().mockResolvedValueOnce(access('old')).mockResolvedValue(access('new'))
    const onError = vi.fn()
    const onConnected = vi.fn()
    client = new RealtimeClient({ fetchAccess, onCursor: () => {}, onConnected, onError, fetch, retryDelays: [1] })

    client.start()
    await until(() => onConnected.mock.calls.length === 1)

    expect(onError).toHaveBeenCalledWith(expect.objectContaining({ message: expect.stringContaining('401') }))
    expect(new Headers(fetch.mock.calls[1]![1]?.headers).get('Authorization')).toBe('Bearer new')
  })

  it('reconnects when the hub closes the stream', async () => {
    const first = stream()
    const second = stream()
    const fetch = vi
      .fn<(url: URL | RequestInfo, init?: RequestInit) => Promise<Response>>()
      .mockResolvedValueOnce(first.response)
      .mockResolvedValue(second.response)
    const onConnected = vi.fn()
    client = new RealtimeClient({ fetchAccess: async () => access('t'), onCursor: () => {}, onConnected, fetch, retryDelays: [1] })

    client.start()
    await until(() => onConnected.mock.calls.length === 1)
    first.close()

    await until(() => onConnected.mock.calls.length === 2)
  })

  it('renews the token before it expires without reporting an error', async () => {
    const fetch = vi.fn(async (_url: URL | RequestInfo, init?: RequestInit) => {
      const hub = stream()
      init?.signal?.addEventListener('abort', () => hub.response.body?.cancel().catch(() => {}))
      return hub.response
    })
    const fetchAccess = vi.fn().mockResolvedValueOnce(access('short', 10)).mockResolvedValue(access('long'))
    const onError = vi.fn()
    const onConnected = vi.fn()
    client = new RealtimeClient({ fetchAccess, onCursor: () => {}, onConnected, onError, fetch, renewBefore: 0 })

    client.start()
    await until(() => onConnected.mock.calls.length === 2)

    expect(onError).not.toHaveBeenCalled()
    expect(fetchAccess).toHaveBeenCalledTimes(2)
  })

  it('stops listening on stop', async () => {
    const hub = stream()
    const onConnected = vi.fn()
    const fetch = vi.fn(async () => hub.response)
    client = new RealtimeClient({ fetchAccess: async () => access('t'), onCursor: () => {}, onConnected, fetch })

    client.start()
    await until(() => onConnected.mock.calls.length === 1)
    await client.stop()
    client = null

    expect(fetch).toHaveBeenCalledTimes(1)
  })
})
