import { computed } from 'vue'
import { defineStore } from 'pinia'
import type { NewSmartInbox, SenderRule, SmartInbox, SmartInboxUpdate } from '@tresso/core'
import { useAuthStore } from '@/stores/auth'
import { useMailStore } from '@/stores/mail'

/**
 * Smart inboxes, read from the synced copy and written through the API, and the corrections the server remembered
 * per sender. After a write the copy is pulled at once; the server classifies recent mail again in the background,
 * and the moved conversations follow through the delta.
 */
export const useSmartInboxesStore = defineStore('smartInboxes', () => {
  const auth = useAuthStore()
  const mail = useMailStore()

  const smartInboxes = computed(() => mail.smartInboxes)

  async function create(smartInbox: NewSmartInbox): Promise<SmartInbox> {
    const created = await auth.send<SmartInbox>('POST', '/api/v1/smart-inboxes', smartInbox)
    await mail.syncNow()

    return created
  }

  async function update(id: string, changes: SmartInboxUpdate): Promise<SmartInbox> {
    const updated = await auth.send<SmartInbox>('PATCH', `/api/v1/smart-inboxes/${encodeURIComponent(id)}`, changes)
    await mail.syncNow()

    return updated
  }

  async function remove(id: string): Promise<void> {
    await auth.send<void>('DELETE', `/api/v1/smart-inboxes/${encodeURIComponent(id)}`)
    await mail.syncNow()
  }

  function senderRules(): Promise<SenderRule[]> {
    return auth.api<SenderRule[]>('/api/v1/sender-rules')
  }

  async function forgetSenderRule(id: string): Promise<void> {
    await auth.send<void>('DELETE', `/api/v1/sender-rules/${encodeURIComponent(id)}`)
  }

  return { smartInboxes, create, update, remove, senderRules, forgetSenderRule }
})
