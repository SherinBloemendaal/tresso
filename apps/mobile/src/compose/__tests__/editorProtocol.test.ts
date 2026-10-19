import { describe, expect, it } from 'vitest'
import { editorScript, linkAddress, parseEditorEvent } from '../editorProtocol'

describe('the editor protocol', () => {
  it('reads only messages the editor sends', () => {
    expect(parseEditorEvent('{"type":"change","html":"<p>Hi</p>"}')).toEqual({ type: 'change', html: '<p>Hi</p>' })
    expect(parseEditorEvent('{"type":"open","url":"https://example.test"}')).toBeNull()
    expect(parseEditorEvent('320')).toBeNull()
    expect(parseEditorEvent('not json')).toBeNull()
    expect(parseEditorEvent('null')).toBeNull()
  })

  it('hands the editor any text exactly as written, without it running as script', () => {
    const html = '<p>"quotes" \'single\' `ticks` </script><script>alert(1)</script> \\   ${x}</p>'
    const script = editorScript({ type: 'set', html })
    let received: string | null = null
    const window = { tresso: { receive: (message: string) => (received = message) } }

    // The script as the web view would run it.
    new Function('window', script)(window)

    expect(received).not.toBeNull()
    expect(JSON.parse(received!)).toEqual({ type: 'set', html })
  })

  it('accepts web and mail addresses as links, and nothing else', () => {
    expect(linkAddress('example.com/page')).toBe('https://example.com/page')
    expect(linkAddress('  https://example.com  ')).toBe('https://example.com/')
    expect(linkAddress('http://example.com')).toBe('http://example.com/')
    expect(linkAddress('anna@example.com')).toBe('mailto:anna@example.com')
    expect(linkAddress('mailto:anna@example.com')).toBe('mailto:anna@example.com')
    expect(linkAddress('javascript:alert(1)')).toBeNull()
    expect(linkAddress('data:text/html,hi')).toBeNull()
    expect(linkAddress('localhost')).toBeNull()
    expect(linkAddress('')).toBeNull()
  })
})
