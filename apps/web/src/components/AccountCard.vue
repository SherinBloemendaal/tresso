<script setup lang="ts">
import FieldLabel from '@/forms/FieldLabel.vue'
import { provideRequiredFields } from '@/forms/required'
import { computed, ref, watch } from 'vue'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import type { MailAccount, MailAccountUpdate } from '@tresso/core'
import { ApiError, errorMessage } from '@/api/errors'
import { accountSchema, serverErrors, type AccountValues } from '@/forms/accounts'
import { fullTime } from '@/mail/format'
import { useAccountsStore } from '@/stores/accounts'
import SenderAvatar from '@/components/SenderAvatar.vue'
import ServerFields from '@/components/ServerFields.vue'

const props = defineProps<{ account: MailAccount }>()

provideRequiredFields(accountSchema)

const accounts = useAccountsStore()
const open = ref(props.account.status === 'auth_failed' || props.account.status === 'error')
const saving = ref(false)
const message = ref<string | null>(null)
const error = ref<string | null>(null)
const confirmingRemoval = ref(false)

function initialValues(): AccountValues {
  return {
    displayName: props.account.displayName,
    username: props.account.username,
    password: '',
    imap: { ...props.account.imap },
    smtp: { ...props.account.smtp },
  }
}

const { defineField, handleSubmit, errors, resetForm, setErrors } = useForm<AccountValues>({
  validationSchema: toTypedSchema(accountSchema),
  initialValues: initialValues(),
})
const [displayName] = defineField('displayName')
const [username] = defineField('username')
const [password] = defineField('password')
const [imap] = defineField('imap')
const [smtp] = defineField('smtp')

function reset(): void {
  resetForm({ values: initialValues() })
}
watch(() => props.account, reset)

const problem = computed(() => props.account.status === 'auth_failed' || props.account.status === 'error')
const statusLabel = computed(
  () =>
    ({
      active: props.account.lastSyncedAt === null ? 'Connecting' : 'Syncing',
      paused: 'Paused',
      auth_failed: 'Sign-in failed',
      error: 'Sync problem',
    })[props.account.status],
)

function changes(values: AccountValues): MailAccountUpdate {
  const changed: MailAccountUpdate = {}
  if (values.displayName !== props.account.displayName) changed.displayName = values.displayName
  if (values.username !== props.account.username) changed.username = values.username
  if (values.password !== '') changed.password = values.password
  if (JSON.stringify(values.imap) !== JSON.stringify(props.account.imap)) changed.imap = values.imap
  if (JSON.stringify(values.smtp) !== JSON.stringify(props.account.smtp)) changed.smtp = values.smtp

  return changed
}

async function run(action: () => Promise<unknown>, done: string): Promise<void> {
  saving.value = true
  error.value = null
  message.value = null
  try {
    await action()
    message.value = done
  } catch (e) {
    error.value = errorMessage(e)
    if (e instanceof ApiError) setErrors(e.violations)
  } finally {
    saving.value = false
  }
}

const save = handleSubmit((values) => {
  const changed = changes(values)
  if (Object.keys(changed).length === 0) {
    message.value = 'Nothing changed.'
    return
  }
  return run(() => accounts.update(props.account.id, changed), changed.password ? 'Saved. Tresso connects again with the new password.' : 'Saved.')
})

function togglePause(): Promise<void> {
  const paused = props.account.status === 'paused'
  return run(() => accounts.update(props.account.id, { status: paused ? 'active' : 'paused' }), paused ? 'Syncing resumed.' : 'Syncing paused.')
}

function remove(): Promise<void> {
  return run(() => accounts.remove(props.account.id), 'Removed.')
}
</script>

<template>
  <section class="card" :class="{ problem }" :aria-label="account.displayName">
    <button type="button" class="head" :aria-expanded="open" @click="open = !open">
      <SenderAvatar :sender="{ name: account.displayName, address: account.emailAddress }" :size="36" />
      <span class="who">
        <span class="name">{{ account.displayName }}</span>
        <span class="address">{{ account.emailAddress }}</span>
      </span>
      <span class="status" :class="account.status">{{ statusLabel }}</span>
    </button>

    <div v-if="open" class="body">
      <div v-if="problem" class="alert" role="alert">
        <strong>{{ account.status === 'auth_failed' ? 'Tresso could not sign in to this account.' : 'Syncing this account failed.' }}</strong>
        <span v-if="account.statusDetail">The server said: {{ account.statusDetail }}</span>
        <span>New mail is not arriving until this is fixed. Check the password (or app password) and the server settings below.</span>
      </div>
      <p v-else class="sync">
        {{ account.lastSyncedAt ? `Last synced ${fullTime(account.lastSyncedAt)}.` : 'Not synced yet.' }}
      </p>

      <form class="form" novalidate @submit.prevent="save">
        <div class="pair">
          <label class="field">
            <FieldLabel name="displayName">Display name</FieldLabel>
            <input v-model="displayName" maxlength="120" :aria-invalid="errors.displayName ? 'true' : undefined" />
            <small class="hint">Recipients see: {{ displayName }} &lt;{{ account.emailAddress }}&gt;</small>
            <small v-if="errors.displayName" class="error">{{ errors.displayName }}</small>
          </label>
          <label class="field">
            <FieldLabel name="username">Username</FieldLabel>
            <input v-model="username" autocomplete="off" spellcheck="false" :aria-invalid="errors.username ? 'true' : undefined" />
            <small v-if="errors.username" class="error">{{ errors.username }}</small>
          </label>
        </div>
        <label class="field">
          <FieldLabel name="password">New password or app password</FieldLabel>
          <input v-model="password" type="password" autocomplete="new-password" placeholder="Leave empty to keep the current one" />
          <small class="hint">Stored encrypted and only used to sync and send your mail.</small>
          <small v-if="errors.password" class="error">{{ errors.password }}</small>
        </label>
        <ServerFields v-if="imap" v-model="imap" protocol="imap" title="Incoming mail" :errors="serverErrors(errors, 'imap')" />
        <ServerFields v-if="smtp" v-model="smtp" protocol="smtp" title="Outgoing mail" :errors="serverErrors(errors, 'smtp')" />

        <p v-if="error" class="form-error" role="alert">{{ error }}</p>
        <p v-if="message" class="done" role="status">{{ message }}</p>

        <div class="actions">
          <button type="submit" class="button button-primary" :disabled="saving">Save changes</button>
          <button type="button" class="button button-quiet" :disabled="saving" @click="reset">Undo changes</button>
          <span class="spacer" />
          <button v-if="account.status === 'active' || account.status === 'paused'" type="button" class="button button-quiet" :disabled="saving" @click="togglePause">
            {{ account.status === 'paused' ? 'Resume syncing' : 'Pause syncing' }}
          </button>
          <button type="button" class="button button-danger" :disabled="saving" @click="confirmingRemoval = true">Remove account</button>
        </div>

        <div v-if="confirmingRemoval" class="confirm" role="alertdialog" aria-labelledby="remove-title">
          <p id="remove-title"><strong>Remove {{ account.emailAddress }}?</strong></p>
          <p>Its mail disappears from Tresso on every device. Nothing is deleted at your provider, and you can add the account again later.</p>
          <div class="actions">
            <button type="button" class="button button-danger" :disabled="saving" @click="remove">Remove account</button>
            <button type="button" class="button button-quiet" @click="confirmingRemoval = false">Keep it</button>
          </div>
        </div>
      </form>
    </div>
  </section>
</template>

<style scoped>
.card {
  border: 1px solid var(--line);
  border-radius: var(--radius-panel);
}
.card.problem {
  border-color: var(--danger);
}
.head {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 14px 16px;
  border: 0;
  background: transparent;
  text-align: left;
  cursor: pointer;
}
.who {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
}
.name {
  font-weight: 600;
}
.address {
  font-size: 13px;
  color: var(--ink-2);
}
.status {
  padding: 2px 10px;
  border-radius: 999px;
  background: var(--ground);
  color: var(--ink-2);
  font-size: 12px;
  font-weight: 600;
}
.status.active {
  background: var(--know-soft);
  color: var(--know);
}
.status.auth_failed,
.status.error {
  background: var(--danger-soft);
  color: var(--danger);
}
.body {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 0 16px 16px;
}
.alert {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 12px 14px;
  border-radius: var(--radius-card);
  background: var(--danger-soft);
  color: var(--ink);
}
.sync {
  margin: 0;
  color: var(--ink-2);
}
.form {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.pair {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}
@media (max-width: 600px) {
  .pair {
    grid-template-columns: minmax(0, 1fr);
  }
}
.actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}
.spacer {
  flex: 1;
}
.done {
  margin: 0;
  color: var(--know);
  font-weight: 500;
}
.confirm {
  padding: 14px;
  border: 1px solid var(--danger);
  border-radius: var(--radius-card);
}
.confirm p {
  margin: 0 0 8px;
}
</style>
