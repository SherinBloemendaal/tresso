import { describe, it, expect, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import App from '../App.vue'
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
})
