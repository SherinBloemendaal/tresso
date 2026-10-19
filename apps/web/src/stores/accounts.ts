import { ref } from 'vue'
import { defineStore } from 'pinia'
import type { MailAccount, MailAccountUpdate, NewMailAccount, SuggestedServerSettings } from '@tresso/core'
import { useAuthStore } from '@/stores/auth'

/** Mail account settings, read and written through the API (the synced copy has no server settings). */
export const useAccountsStore = defineStore('accounts', () => {
  const auth = useAuthStore()
  const accounts = ref<MailAccount[]>([])
  const loaded = ref(false)

  async function load(): Promise<void> {
    accounts.value = await auth.api<MailAccount[]>('/api/v1/mail-accounts')
    loaded.value = true
  }

  async function get(id: string): Promise<MailAccount> {
    const account = await auth.api<MailAccount>(`/api/v1/mail-accounts/${encodeURIComponent(id)}`)
    accounts.value = accounts.value.some((a) => a.id === id) ? accounts.value.map((a) => (a.id === id ? account : a)) : [...accounts.value, account]

    return account
  }

  async function create(account: NewMailAccount): Promise<MailAccount> {
    const created = await auth.send<MailAccount>('POST', '/api/v1/mail-accounts', account)
    accounts.value = [...accounts.value, created]

    return created
  }

  async function update(id: string, changes: MailAccountUpdate): Promise<MailAccount> {
    const updated = await auth.send<MailAccount>('PATCH', `/api/v1/mail-accounts/${encodeURIComponent(id)}`, changes)
    accounts.value = accounts.value.map((account) => (account.id === id ? updated : account))

    return updated
  }

  async function remove(id: string): Promise<void> {
    await auth.send<void>('DELETE', `/api/v1/mail-accounts/${encodeURIComponent(id)}`)
    accounts.value = accounts.value.filter((account) => account.id !== id)
  }

  function suggest(emailAddress: string): Promise<SuggestedServerSettings> {
    return auth.api<SuggestedServerSettings>(`/api/v1/mail-server-settings?${new URLSearchParams({ emailAddress })}`)
  }

  return { accounts, loaded, load, get, create, update, remove, suggest }
})
