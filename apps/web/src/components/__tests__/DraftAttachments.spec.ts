import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import DraftAttachments from '../DraftAttachments.vue'

describe('DraftAttachments', () => {
  const notes = { id: 'f1', filename: 'Q4 notes.pdf', contentType: 'application/pdf', size: 240 * 1024, scanStatus: 'clean' as const }
  const virus = { id: 'f2', filename: 'invoice.exe', contentType: 'application/octet-stream', size: 1000, scanStatus: 'infected' as const }

  it('lists the files with their size and scan, and the ones still on their way', () => {
    const wrapper = mount(DraftAttachments, { props: { attachments: [notes, virus], attaching: ['slow.bin'] } })

    expect(wrapper.text()).toContain('Q4 notes.pdf240 KB, scanned, no threats')
    expect(wrapper.text()).toContain('blocked: contains malware')
    expect(wrapper.text()).toContain('slow.binAttaching…')
    expect(wrapper.find('[aria-busy="true"]').exists()).toBe(true)
  })

  it('opens and removes files, but does not open one with malware', async () => {
    const wrapper = mount(DraftAttachments, { props: { attachments: [notes, virus], attaching: [] } })

    await wrapper.findAll('button.name')[0]!.trigger('click')
    await wrapper.find('button[aria-label="Remove invoice.exe"]').trigger('click')

    expect(wrapper.emitted('open')).toEqual([['f1']])
    expect(wrapper.emitted('remove')).toEqual([['f2']])
    expect(wrapper.findAll('button.name')[1]!.attributes('disabled')).toBeDefined()
  })

  it('opens a file that could not be scanned only after the warning', async () => {
    const statement = { id: 'f3', filename: 'statement.pdf', contentType: 'application/pdf', size: 1000, scanStatus: 'failed' as const }
    const wrapper = mount(DraftAttachments, { props: { attachments: [statement], attaching: [] } })

    await wrapper.get('button.name').trigger('click')
    expect(wrapper.emitted('open')).toBeUndefined()
    expect(wrapper.text()).toContain('Not checked for malware.')

    await wrapper.get('button.confirm-link').trigger('click')

    expect(wrapper.emitted('openUnscanned')).toEqual([['f3']])
  })

  it('shows nothing without files', () => {
    expect(mount(DraftAttachments, { props: { attachments: [], attaching: [] } }).find('ul').exists()).toBe(false)
  })
})
