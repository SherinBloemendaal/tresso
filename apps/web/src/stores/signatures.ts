import { computed } from 'vue'
import { defineStore } from 'pinia'
import type { NewSignature, Signature, SignatureUpdate } from '@tresso/core'
import { useAuthStore } from '@/stores/auth'
import { useMailStore } from '@/stores/mail'

/**
 * Signatures, read from the synced copy and written through the API. After a write the copy is pulled at once, so
 * screens show the sanitized HTML and the moved defaults the server stored.
 */
export const useSignaturesStore = defineStore('signatures', () => {
  const auth = useAuthStore()
  const mail = useMailStore()

  const signatures = computed(() => mail.signatures)
  const accounts = computed(() => mail.accounts)

  /** Ids of the mail accounts that insert the signature by default. */
  function defaultFor(signatureId: string): string[] {
    return mail.accounts.filter((account) => account.defaultSignatureId === signatureId).map((account) => account.id)
  }

  async function create(signature: NewSignature): Promise<Signature> {
    const created = await auth.send<Signature>('POST', '/api/v1/signatures', signature)
    await mail.syncNow()

    return created
  }

  async function update(id: string, changes: SignatureUpdate): Promise<Signature> {
    const updated = await auth.send<Signature>('PATCH', `/api/v1/signatures/${encodeURIComponent(id)}`, changes)
    await mail.syncNow()

    return updated
  }

  async function remove(id: string): Promise<void> {
    await auth.send<void>('DELETE', `/api/v1/signatures/${encodeURIComponent(id)}`)
    await mail.syncNow()
  }

  return { signatures, accounts, defaultFor, create, update, remove }
})
