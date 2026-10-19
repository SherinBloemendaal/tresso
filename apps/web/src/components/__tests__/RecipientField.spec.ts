import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type { Address } from '@tresso/core'
import RecipientField from '../RecipientField.vue'

function mountField(initial: Address[] = []) {
  setActivePinia(createPinia())
  const updates: Address[][] = []
  const wrapper = mount(RecipientField, {
    props: { label: 'To', modelValue: initial, 'onUpdate:modelValue': (value: Address[]) => updates.push(value) },
  })
  return { wrapper, updates }
}

describe('RecipientField', () => {
  it('turns typed addresses into recipients on Enter', async () => {
    const { wrapper, updates } = mountField()

    await wrapper.get('input').setValue('anna@finance.example')
    await wrapper.get('input').trigger('keydown', { key: 'Enter' })

    expect(updates[updates.length - 1]).toEqual([{ address: 'anna@finance.example', name: null }])
  })

  it('splits a pasted list and skips duplicates', async () => {
    const { wrapper, updates } = mountField([{ address: 'anna@finance.example', name: 'Anna' }])

    await wrapper.get('input').trigger('paste', { clipboardData: { getData: () => 'Anna <ANNA@finance.example>, Pieter <pieter@company.example>' } })

    expect(updates[updates.length - 1]).toEqual([
      { address: 'anna@finance.example', name: 'Anna' },
      { address: 'pieter@company.example', name: 'Pieter' },
    ])
  })

  it('removes the last recipient with Backspace in an empty field', async () => {
    const { wrapper, updates } = mountField([{ address: 'anna@finance.example', name: null }, { address: 'pieter@company.example', name: null }])

    await wrapper.get('input').trigger('keydown', { key: 'Backspace' })

    expect(updates[updates.length - 1]).toEqual([{ address: 'anna@finance.example', name: null }])
  })

  it('marks addresses that cannot be right', () => {
    const { wrapper } = mountField([{ address: 'anna@', name: null }])

    expect(wrapper.get('.chip').classes()).toContain('wrong')
  })
})
