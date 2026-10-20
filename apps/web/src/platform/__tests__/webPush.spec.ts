import { describe, expect, it } from 'vitest'
import { applicationServerKey, browserName, deviceOf, openedPath } from '../webPush'

describe('applicationServerKey', () => {
  it('reads the base64url VAPID key without padding as its bytes', () => {
    expect([...applicationServerKey('AQID_-8')]).toEqual([1, 2, 3, 255, 239])
  })
})

describe('deviceOf', () => {
  it('registers the subscription with its keys', () => {
    expect(deviceOf({ endpoint: 'https://push.example.test/1', keys: { p256dh: 'p', auth: 'a' } }, 'Firefox on macOS')).toEqual({
      kind: 'web_push',
      endpoint: 'https://push.example.test/1',
      publicKey: 'p',
      authSecret: 'a',
      name: 'Firefox on macOS',
    })
  })

  it('refuses a subscription without keys', () => {
    expect(() => deviceOf({ endpoint: 'https://push.example.test/1' }, 'Firefox')).toThrow('without its keys')
  })
})

describe('browserName', () => {
  it('names the browser and the system', () => {
    expect(browserName('Mozilla/5.0 (Macintosh; Intel Mac OS X 15_6) Gecko/20100101 Firefox/150.0')).toBe('Firefox on macOS')
    expect(browserName('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/150.0 Safari/537.36 Edg/150.0')).toBe('Edge on Windows')
    expect(browserName('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/150.0 Safari/537.36')).toBe('Chrome on Linux')
    expect(browserName('Unknown')).toBe('Browser')
  })
})

describe('openedPath', () => {
  it('takes the path a clicked notification opens, and nothing else', () => {
    expect(openedPath({ type: 'tresso:open', path: '/thread/t1' })).toBe('/thread/t1')
    expect(openedPath({ type: 'tresso:open', path: 'https://elsewhere.example/' })).toBeNull()
    expect(openedPath({ type: 'other', path: '/thread/t1' })).toBeNull()
    expect(openedPath('tresso:open')).toBeNull()
  })
})
