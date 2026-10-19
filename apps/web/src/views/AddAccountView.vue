<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { defaultPort, type MailAccount, type ServerSettings, type SuggestedServerSettings } from '@tresso/core'
import { ApiError, errorMessage } from '@/api/errors'
import { useAccountsStore } from '@/stores/accounts'
import { useAuthStore } from '@/stores/auth'
import { useMailStore } from '@/stores/mail'
import ServerFields from '@/components/ServerFields.vue'

type Step = 'address' | 'settings' | 'connecting'

/** How long to wait for the first sync before telling the user it continues in the background. */
const PATIENCE_MS = 45_000

const auth = useAuthStore()
const accounts = useAccountsStore()
const mail = useMailStore()

const step = ref<Step>('address')
const emailAddress = ref('')
const displayName = ref(auth.me?.displayName ?? '')
const username = ref('')
const password = ref('')
const imap = ref<ServerSettings>({ host: '', port: defaultPort('imap', 'tls'), security: 'tls' })
const smtp = ref<ServerSettings>({ host: '', port: defaultPort('smtp', 'tls'), security: 'tls' })
const suggestion = ref<SuggestedServerSettings | null>(null)
const busy = ref(false)
const error = ref<string | null>(null)
const violations = ref<Record<string, string>>({})
/** The created account; a second submit fixes it instead of creating another. */
const accountId = ref<string | null>(null)
const waitedTooLong = ref(false)
/** The account as the API last returned it: the create or update response, then a fresh read after every change. */
const current = ref<MailAccount | null>(null)
let patience: ReturnType<typeof setTimeout> | null = null

const domain = computed(() => emailAddress.value.split('@')[1] ?? '')
const passwordUnusual = computed(() => suggestion.value?.found === true && !suggestion.value.authentication.includes('password'))
const outcome = computed<'waiting' | 'connected' | 'failed'>(() => {
  const account = current.value
  if (account === null) return 'waiting'
  if (account.status === 'auth_failed' || account.status === 'error') return 'failed'
  return account.lastSyncedAt !== null ? 'connected' : 'waiting'
})

function fieldErrors(prefix: string): Record<string, string> {
  const result: Record<string, string> = {}
  for (const [path, text] of Object.entries(violations.value)) {
    if (path.startsWith(`${prefix}.`)) result[path.slice(prefix.length + 1)] = text
  }
  return result
}

async function findSettings(): Promise<void> {
  busy.value = true
  error.value = null
  try {
    const found = await accounts.suggest(emailAddress.value.trim())
    suggestion.value = found
    username.value = found.username ?? emailAddress.value.trim()
    if (found.imap && found.smtp) {
      imap.value = found.imap
      smtp.value = found.smtp
    }
    step.value = 'settings'
  } catch (e) {
    violations.value = e instanceof ApiError ? e.violations : {}
    error.value = violations.value.emailAddress ? null : errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function connect(): Promise<void> {
  busy.value = true
  error.value = null
  violations.value = {}
  try {
    if (accountId.value === null) {
      const created = await accounts.create({
        emailAddress: emailAddress.value.trim(),
        displayName: displayName.value.trim(),
        username: username.value.trim(),
        password: password.value,
        imap: imap.value,
        smtp: smtp.value,
      })
      accountId.value = created.id
      current.value = created
    } else {
      current.value = await accounts.update(accountId.value, { username: username.value.trim(), password: password.value, imap: imap.value, smtp: smtp.value })
    }
    password.value = ''
    step.value = 'connecting'
    startWaiting()
  } catch (e) {
    error.value = errorMessage(e)
    violations.value = e instanceof ApiError ? e.violations : {}
  } finally {
    busy.value = false
  }
}

function startWaiting(): void {
  waitedTooLong.value = false
  if (patience !== null) clearTimeout(patience)
  patience = setTimeout(() => (waitedTooLong.value = true), PATIENCE_MS)
}

// The worker's progress reaches the synced copy in real time; read the account again whenever it changes there.
watch(
  () => {
    const synced = mail.accounts.find((account) => account.id === accountId.value)
    return synced ? `${synced.status}:${synced.statusDetail}:${synced.lastSyncedAt}` : null
  },
  async (signature) => {
    if (signature === null || accountId.value === null || step.value !== 'connecting') return
    try {
      current.value = await accounts.get(accountId.value)
    } catch (e) {
      error.value = errorMessage(e)
    }
  },
)

watch(outcome, (value) => {
  if (value !== 'waiting' && patience !== null) {
    clearTimeout(patience)
    patience = null
  }
})
onBeforeUnmount(() => {
  if (patience !== null) clearTimeout(patience)
})
</script>

<template>
  <section class="add">
    <RouterLink :to="{ name: 'settings-accounts' }" class="back">← Accounts</RouterLink>

    <form v-if="step === 'address'" class="form" @submit.prevent="findSettings">
      <h2>Connect a mail account</h2>
      <p class="lead">Work, support, personal: add every account you read. They all land in one Unified Inbox.</p>
      <label class="field">
        <span>Email address</span>
        <input v-model="emailAddress" type="email" required autocomplete="email" maxlength="320" :aria-invalid="violations.emailAddress ? 'true' : undefined" />
        <small v-if="violations.emailAddress" class="error">{{ violations.emailAddress }}</small>
      </label>
      <label class="field">
        <span>Display name</span>
        <input v-model="displayName" required maxlength="120" />
        <small class="hint">Shown to people you write to.</small>
      </label>
      <p v-if="error" class="form-error" role="alert">{{ error }}</p>
      <div class="actions">
        <button type="submit" class="button button-primary" :disabled="busy">{{ busy ? 'Looking up your provider…' : 'Continue' }}</button>
      </div>
    </form>

    <form v-else-if="step === 'settings'" class="form" @submit.prevent="connect">
      <h2>{{ emailAddress }}</h2>
      <p class="lead">
        <template v-if="suggestion?.found">We filled in the settings we found for {{ domain }}. Check them, then enter your password.</template>
        <template v-else>We could not find the settings for {{ domain }}. Your provider lists them in its help pages.</template>
      </p>
      <p v-if="passwordUnusual" class="note">
        This provider normally signs you in through its own page, which Tresso will support later. Until then, create an app password at your provider and use that.
      </p>

      <label class="field">
        <span>Username</span>
        <input v-model="username" required autocomplete="off" spellcheck="false" :aria-invalid="violations.username ? 'true' : undefined" />
        <small v-if="violations.username" class="error">{{ violations.username }}</small>
      </label>
      <label class="field">
        <span>Password or app password</span>
        <input v-model="password" type="password" required autocomplete="current-password" :aria-invalid="violations.password ? 'true' : undefined" />
        <small class="hint">Stored encrypted and only used to sync and send your mail.</small>
        <small v-if="violations.password" class="error">{{ violations.password }}</small>
      </label>
      <ServerFields v-model="imap" protocol="imap" title="Incoming mail" :errors="fieldErrors('imap')" />
      <ServerFields v-model="smtp" protocol="smtp" title="Outgoing mail" :errors="fieldErrors('smtp')" />

      <p v-if="error" class="form-error" role="alert">{{ error }}</p>
      <div class="actions">
        <button type="submit" class="button button-primary" :disabled="busy">{{ accountId ? 'Try again' : 'Connect' }}</button>
        <button v-if="!accountId" type="button" class="button button-quiet" :disabled="busy" @click="step = 'address'">Back</button>
      </div>
    </form>

    <div v-else class="form" aria-live="polite">
      <h2>{{ emailAddress }}</h2>
      <p v-if="error" class="form-error" role="alert">{{ error }}</p>
      <template v-if="outcome === 'connected'">
        <p class="success">Connected. Your mail is arriving, newest first.</p>
        <div class="actions">
          <RouterLink :to="{ name: 'account', params: { accountId: accountId } }" class="button button-primary">Open the inbox</RouterLink>
          <RouterLink :to="{ name: 'settings-accounts' }" class="button button-quiet">Back to accounts</RouterLink>
        </div>
      </template>
      <template v-else-if="outcome === 'failed'">
        <div class="alert" role="alert">
          <strong>{{ current?.status === 'auth_failed' ? 'Signing in failed.' : 'Connecting failed.' }}</strong>
          <span v-if="current?.statusDetail">The server said: {{ current.statusDetail }}</span>
        </div>
        <div class="actions">
          <button type="button" class="button button-primary" @click="step = 'settings'">Change the settings</button>
          <RouterLink :to="{ name: 'settings-accounts' }" class="button button-quiet">Back to accounts</RouterLink>
        </div>
      </template>
      <template v-else>
        <p class="lead">Signing in and fetching your folders…</p>
        <p v-if="waitedTooLong" class="note">
          This takes longer than usual. You can leave this page: the account keeps connecting, and its status shows under Accounts.
        </p>
      </template>
    </div>
  </section>
</template>

<style scoped>
.add {
  display: flex;
  flex-direction: column;
  gap: 16px;
  max-width: 640px;
}
.back {
  color: var(--ink-2);
  text-decoration: none;
  font-weight: 600;
  font-size: 13px;
}
.form {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
h2 {
  margin: 0;
  font: 700 28px/34px var(--font-display);
  overflow-wrap: anywhere;
}
.lead {
  margin: 0;
  color: var(--ink-2);
}
.note {
  margin: 0;
  padding: 12px 14px;
  border-radius: var(--radius-card);
  background: var(--delegate-soft);
  color: var(--ink);
}
.success {
  margin: 0;
  color: var(--know);
  font-weight: 600;
}
.alert {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 12px 14px;
  border-radius: var(--radius-card);
  background: var(--danger-soft);
}
.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.actions .button {
  text-decoration: none;
}
</style>
