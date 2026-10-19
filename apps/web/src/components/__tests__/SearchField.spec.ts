import { afterEach, describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import SearchField from '../SearchField.vue'

async function settle(): Promise<void> {
  for (let i = 0; i < 5; i++) {
    await flushPromises()
    await new Promise((resolve) => setTimeout(resolve, 0))
  }
}

async function mountField(props: { query?: string } = {}) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'unified', component: { template: '<div />' } },
      { path: '/search', name: 'search', component: { template: '<div />' } },
    ],
  })
  await router.push('/')
  await router.isReady()
  const wrapper = mount(SearchField, { props, attachTo: document.body, global: { plugins: [router] } })

  return { wrapper, router }
}

describe('SearchField', () => {
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('searches on enter', async () => {
    const { wrapper, router } = await mountField()

    await wrapper.get('input').setValue('  budget from:anna ')
    await wrapper.get('form').trigger('submit')
    await settle()

    expect(router.currentRoute.value.name).toBe('search')
    expect(router.currentRoute.value.query.q).toBe('budget from:anna')
  })

  it('does nothing for an empty search', async () => {
    const { wrapper, router } = await mountField()

    await wrapper.get('input').setValue('   ')
    await wrapper.get('form').trigger('submit')
    await settle()

    expect(router.currentRoute.value.name).toBe('unified')
  })

  it('jumps to the field on slash, but not while typing elsewhere', async () => {
    const { wrapper } = await mountField()
    const other = document.createElement('textarea')
    document.body.appendChild(other)

    other.focus()
    other.dispatchEvent(new KeyboardEvent('keydown', { key: '/', bubbles: true }))
    expect(document.activeElement).toBe(other)

    other.blur()
    document.body.dispatchEvent(new KeyboardEvent('keydown', { key: '/', bubbles: true }))
    expect(document.activeElement).toBe(wrapper.get('input').element)
  })

  it('shows the filters it understands', async () => {
    const { wrapper } = await mountField({ query: 'budget from:anna in:"Work stuff" https://x.example' })

    expect(wrapper.findAll('.filter').map((filter) => filter.text())).toEqual(['from:anna', 'in:"Work stuff"'])
  })

  it('clears on escape, then lets go of the focus', async () => {
    const { wrapper } = await mountField({ query: 'budget' })
    const input = wrapper.get('input')
    ;(input.element as HTMLInputElement).focus()

    await input.trigger('keydown', { key: 'Escape' })
    await settle()
    expect((input.element as HTMLInputElement).value).toBe('')
    expect(document.activeElement).toBe(input.element)

    await input.trigger('keydown', { key: 'Escape' })
    expect(document.activeElement).not.toBe(input.element)
  })

  it('follows a new query from the route', async () => {
    const { wrapper } = await mountField({ query: 'budget' })

    await wrapper.setProps({ query: 'invoice' })
    await settle()

    expect((wrapper.get('input').element as HTMLInputElement).value).toBe('invoice')
  })
})
