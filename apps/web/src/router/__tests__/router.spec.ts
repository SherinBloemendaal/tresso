import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { mailNotificationPath } from '@tresso/core'
import router from '../index'

describe('router', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('opens the conversation a notification leads to in the Unified Inbox', async () => {
    await router.push(mailNotificationPath('t1'))

    expect(router.currentRoute.value.name).toBe('unified-thread')
    expect(router.currentRoute.value.params.threadId).toBe('t1')
  })
})
