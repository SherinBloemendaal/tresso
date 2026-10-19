import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AttachmentList from '../AttachmentList.vue'
import MessageFrame from '../MessageFrame.vue'
import PlainTextBody from '../PlainTextBody.vue'

describe('MessageFrame', () => {
  it('renders mail in a frame that cannot run scripts', () => {
    const frame = mount(MessageFrame, { props: { html: '<p>Hi</p>', allowRemoteContent: false } }).get('iframe')

    const sandbox = (frame.attributes('sandbox') ?? '').split(/\s+/)
    expect(sandbox).not.toContain('allow-scripts')
    expect(sandbox).not.toContain('allow-forms')
    expect(sandbox).not.toContain('allow-top-navigation')
    expect(frame.attributes('srcdoc')).toContain('<body><p>Hi</p></body>')
    expect(frame.attributes('srcdoc')).toContain("default-src 'none'")
    expect(frame.attributes('srcdoc')).toContain('img-src http://localhost:8080 data:;')
  })

  it('opens images to the internet only when allowed', () => {
    const frame = mount(MessageFrame, { props: { html: '<p>Hi</p>', allowRemoteContent: true } }).get('iframe')

    expect(frame.attributes('srcdoc')).toContain('img-src http://localhost:8080 data: https: http:')
  })
})

describe('PlainTextBody', () => {
  it('shows markup as text and links only web and mail addresses', () => {
    const wrapper = mount(PlainTextBody, {
      props: { text: 'See https://example.test/a?b=1. <script>x</script> javascript:alert(1) mailto:kim@example.test' },
    })

    expect(wrapper.find('script').exists()).toBe(false)
    expect(wrapper.text()).toContain('<script>x</script>')
    expect(wrapper.findAll('a').map((a) => a.attributes('href'))).toEqual(['https://example.test/a?b=1', 'mailto:kim@example.test'])
    expect(wrapper.get('a').attributes('rel')).toBe('noopener noreferrer')
  })
})

describe('AttachmentList', () => {
  it('offers no link for quarantined files', () => {
    const wrapper = mount(AttachmentList, {
      props: {
        attachments: [
          { id: '1', filename: 'invoice.pdf', contentType: 'application/pdf', size: 2048, inline: false, contentId: null, scanStatus: 'clean', url: 'http://localhost:8080/content/attachments/1?_hash=x' },
          { id: '2', filename: 'payload.exe', contentType: 'application/octet-stream', size: 10, inline: false, contentId: null, scanStatus: 'infected', url: null },
        ],
      },
    })

    expect(wrapper.findAll('a')).toHaveLength(1)
    expect(wrapper.text()).toContain('2.0 KB, scanned, clean')
    expect(wrapper.text()).toContain('blocked: contains malware')
  })
})
