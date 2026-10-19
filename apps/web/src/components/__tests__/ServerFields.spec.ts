import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import type { ServerSettings } from '@tresso/core'
import ServerFields from '../ServerFields.vue'

function mountFields(settings: ServerSettings) {
  const updates: ServerSettings[] = []
  const wrapper = mount(ServerFields, {
    props: { protocol: 'smtp', title: 'Outgoing mail', modelValue: settings, 'onUpdate:modelValue': (value: ServerSettings) => updates.push(value) },
  })
  return { wrapper, updates }
}

describe('ServerFields', () => {
  it('moves a usual port along with the security mode', async () => {
    const { wrapper, updates } = mountFields({ host: 'smtp.example.test', port: 465, security: 'tls' })

    await wrapper.get('select').setValue('starttls')

    expect(updates[updates.length - 1]).toEqual({ host: 'smtp.example.test', port: 587, security: 'starttls' })
  })

  it('keeps a custom port', async () => {
    const { wrapper, updates } = mountFields({ host: 'smtp.example.test', port: 2525, security: 'tls' })

    await wrapper.get('select').setValue('starttls')

    expect(updates[updates.length - 1]?.port).toBe(2525)
  })

  it('shows validation messages next to the field', () => {
    const wrapper = mount(ServerFields, {
      props: { protocol: 'imap', title: 'Incoming mail', modelValue: { host: '10.0.0.1', port: 993, security: 'tls' }, errors: { host: 'This server is on a private network.' } },
    })

    expect(wrapper.get('input').attributes('aria-invalid')).toBe('true')
    expect(wrapper.text()).toContain('This server is on a private network.')
  })
})
