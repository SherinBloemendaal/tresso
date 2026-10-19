import { SseParser } from './sse'
import type { RealtimeAccess } from './types'

export interface RealtimeOptions {
  /** GET /api/v1/realtime/token */
  fetchAccess(): Promise<RealtimeAccess>
  /** The user's changes reached this cursor. */
  onCursor(cursor: number): void
  /**
   * The stream is open. Notifications sent while it was down are not replayed, so pull the delta here to catch up.
   */
  onConnected?(): void
  onError?(error: unknown): void
  fetch?: typeof fetch
  /** Delays between failed attempts, in ms; the last one repeats. */
  retryDelays?: readonly number[]
  /** Reconnect with a fresh token this long before the current one expires, in ms. */
  renewBefore?: number
}

const DEFAULT_RETRY_DELAYS = [1_000, 2_000, 5_000, 10_000, 30_000]
const DEFAULT_RENEW_BEFORE = 60_000

/**
 * Listens to the user's private topic on the Mercure hub. Browsers' EventSource cannot send the access token, so the
 * stream is read through fetch(). Reconnects with backoff after failures and renews the token before it expires.
 */
export class RealtimeClient {
  private controller: AbortController | null = null
  private stopped = true
  private loop: Promise<void> | null = null
  private failures = 0
  /** Which run is current: a start right after a stop must not leave the old run going next to the new one. */
  private generation = 0

  constructor(private readonly options: RealtimeOptions) {}

  start(): void {
    if (!this.stopped) {
      return
    }
    this.stopped = false
    this.loop = this.run(++this.generation)
  }

  async stop(): Promise<void> {
    this.stopped = true
    this.generation++
    this.controller?.abort()
    const loop = this.loop
    await loop
    if (this.loop === loop) {
      this.loop = null
    }
  }

  private current(generation: number): boolean {
    return !this.stopped && generation === this.generation
  }

  private async run(generation: number): Promise<void> {
    const delays = this.options.retryDelays ?? DEFAULT_RETRY_DELAYS
    this.failures = 0
    while (this.current(generation)) {
      try {
        await this.listen()
      } catch (error) {
        if (!this.current(generation)) {
          return
        }
        this.options.onError?.(error)
        const delay = delays[Math.min(this.failures, delays.length - 1)] ?? DEFAULT_RETRY_DELAYS[DEFAULT_RETRY_DELAYS.length - 1]
        this.failures++
        await this.sleep(delay ?? 30_000)
      }
    }
  }

  /** Resolves when the token is due for renewal; rejects when the connection fails. */
  private async listen(): Promise<void> {
    const access = await this.options.fetchAccess()
    const controller = new AbortController()
    this.controller = controller
    let renewing = false
    const renewIn = Date.parse(access.expiresAt) - Date.now() - (this.options.renewBefore ?? DEFAULT_RENEW_BEFORE)
    const renewal = setTimeout(() => {
      renewing = true
      controller.abort()
    }, Math.max(renewIn, 0))

    try {
      const url = new URL(access.hubUrl)
      url.searchParams.set('match', access.topic)
      const response = await (this.options.fetch ?? fetch)(url, {
        headers: { Authorization: `Bearer ${access.token}`, Accept: 'text/event-stream' },
        signal: controller.signal,
        cache: 'no-store',
      })
      if (!response.ok || response.body === null) {
        throw new Error(`The realtime hub refused the connection (${response.status}).`)
      }
      this.failures = 0
      this.options.onConnected?.()

      const parser = new SseParser()
      // TextDecoder in streaming mode rather than TextDecoderStream, which React Native does not have.
      const decoder = new TextDecoder()
      const reader = response.body.getReader()
      // Ends a pending read on stop or renewal even where aborting the request does not end the body stream.
      const cancel = () => void reader.cancel().catch(() => {})
      if (controller.signal.aborted) {
        cancel()
      }
      controller.signal.addEventListener('abort', cancel, { once: true })
      for (;;) {
        const { done, value } = await reader.read()
        if (done) {
          throw new Error('The realtime hub closed the connection.')
        }
        for (const event of parser.push(decoder.decode(value, { stream: true }))) {
          this.handle(event.type, event.data)
        }
      }
    } catch (error) {
      if (renewing || this.stopped) {
        return
      }
      throw error
    } finally {
      clearTimeout(renewal)
      if (this.controller === controller) {
        this.controller = null
      }
    }
  }

  private handle(type: string, data: string): void {
    if (type !== 'changes') {
      return
    }
    let cursor: unknown
    try {
      cursor = (JSON.parse(data) as { cursor?: unknown }).cursor
    } catch {
      return
    }
    if (typeof cursor === 'number' && Number.isSafeInteger(cursor) && cursor >= 0) {
      this.options.onCursor(cursor)
    }
  }

  /** Waits before the next attempt; stop() ends the wait, also when it came just before the wait began. */
  private sleep(ms: number): Promise<void> {
    return new Promise((resolve) => {
      if (this.stopped) {
        resolve()
        return
      }
      const controller = new AbortController()
      this.controller = controller
      const timer = setTimeout(resolve, ms)
      controller.signal.addEventListener('abort', () => {
        clearTimeout(timer)
        resolve()
      })
    })
  }
}
