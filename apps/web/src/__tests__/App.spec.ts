import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import App from '../App.vue'
import { useMailStore } from '../stores/mail'
import HomeView from '../views/HomeView.vue'

describe('App', () => {
  beforeEach(() => {
    sessionStorage.clear()
    setActivePinia(createPinia())
  })

  it('offers sign in when signed out', async () => {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', name: 'unified', component: HomeView }] })
    await router.push('/')
    await router.isReady()

    const wrapper = mount(App, { global: { plugins: [router] } })
    await flushPromises()

    expect(wrapper.text()).toContain('Sign in')
  })

  it('says to reload, with a button that does, once another tab moved to a newer Tresso', async () => {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', name: 'unified', component: HomeView }] })
    await router.push('/')
    await router.isReady()
    const reload = vi.fn<() => void>()
    vi.stubGlobal('location', { ...window.location, reload })

    const wrapper = mount(App, { global: { plugins: [router] } })
    useMailStore().updatedElsewhere = 'Tresso was updated in another tab. Reload this tab to keep using it.'
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('Tresso was updated in another tab.')
    await wrapper.get('[role="alert"] button').trigger('click')
    expect(reload).toHaveBeenCalled()
    vi.unstubAllGlobals()
  })
})
