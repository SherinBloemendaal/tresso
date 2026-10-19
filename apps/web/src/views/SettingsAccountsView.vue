<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { errorMessage } from '@/api/errors'
import { useAccountsStore } from '@/stores/accounts'
import { useMailStore } from '@/stores/mail'
import AccountCard from '@/components/AccountCard.vue'

const accounts = useAccountsStore()
const mail = useMailStore()
const error = ref<string | null>(null)

async function load(): Promise<void> {
  try {
    await accounts.load()
    error.value = null
  } catch (e) {
    error.value = errorMessage(e)
  }
}

onMounted(load)
// Status changes (a sign-in failure, a first sync) reach the synced copy in real time; refresh the settings then.
watch(() => mail.accounts.map((a) => `${a.id}:${a.status}:${a.statusDetail}:${a.lastSyncedAt}`).join('|'), load)
</script>

<template>
  <section class="accounts">
    <header class="header">
      <div>
        <h2>Accounts</h2>
        <p>{{ accounts.accounts.length === 1 ? '1 mail account.' : `${accounts.accounts.length} mail accounts.` }} Your mail stays with your provider; passwords are stored encrypted.</p>
      </div>
      <RouterLink :to="{ name: 'settings-accounts-new' }" class="button button-primary">Add account</RouterLink>
    </header>

    <p v-if="error" class="form-error" role="alert">{{ error }}</p>
    <p v-else-if="!accounts.loaded" class="state">Loading accounts…</p>
    <p v-else-if="accounts.accounts.length === 0" class="state">No mail accounts yet. Add one to see your mail in Tresso.</p>

    <AccountCard v-for="account in accounts.accounts" :key="account.id" :account="account" />
  </section>
</template>

<style scoped>
.accounts {
  display: flex;
  flex-direction: column;
  gap: 16px;
  max-width: 760px;
}
.header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}
h2 {
  margin: 0 0 4px;
  font-size: 20px;
  line-height: 28px;
}
.header p,
.state {
  margin: 0;
  color: var(--ink-2);
}
.header .button {
  text-decoration: none;
  flex-shrink: 0;
}
</style>
