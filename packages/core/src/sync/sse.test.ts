import { describe, expect, it } from 'vitest'
import { SseParser } from './sse'

describe('SseParser', () => {
  it('parses the events the hub sends', () => {
    const events = new SseParser().push(':\n\nevent: changes\nid: urn:uuid:1\ndata: {"cursor":10}\n\n')

    expect(events).toEqual([{ type: 'changes', data: '{"cursor":10}', id: 'urn:uuid:1' }])
  })

  it('joins multi-line data and defaults the type to message', () => {
    expect(new SseParser().push('data: one\ndata:two\n\n')).toEqual([{ type: 'message', data: 'one\ntwo', id: null }])
  })

  it('handles events split across chunks at any point', () => {
    const stream = 'event: changes\r\ndata: {"cursor":1}\r\n\r\nevent: changes\rdata: {"cursor":2}\r\r'
    for (let size = 1; size <= stream.length; size++) {
      const parser = new SseParser()
      const events = []
      for (let i = 0; i < stream.length; i += size) {
        events.push(...parser.push(stream.slice(i, i + size)))
      }
      expect(events.map((e) => e.data), `chunk size ${size}`).toEqual(['{"cursor":1}', '{"cursor":2}'])
    }
  })

  it('ignores comments, unknown fields and events without data', () => {
    expect(new SseParser().push(': keep-alive\nretry: 100\nfoo: bar\nevent: changes\n\n')).toEqual([])
  })

  it('keeps the last id for later events', () => {
    const parser = new SseParser()
    parser.push('id: a\ndata: 1\n\n')

    expect(parser.push('data: 2\n\n')).toEqual([{ type: 'message', data: '2', id: 'a' }])
  })
})
