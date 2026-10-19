<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import type { Draft } from '@/mail/db'
import { composeSchema, type ComposeValues } from '@/forms/compose'
import { useComposeStore } from '@/stores/compose'
import { useMailStore } from '@/stores/mail'
import RecipientField from '@/components/RecipientField.vue'
import RichEditor from '@/components/RichEditor.vue'

const props = defineProps<{ draft: Draft }>()

const compose = useComposeStore()
const mail = useMailStore()
const showCopies = ref(props.draft.cc.length > 0 || props.draft.bcc.length > 0)
const laterOpen = ref(false)
const customTime = ref('')
const timeError = ref<string | null>(null)

const { defineField, handleSubmit, errors, values, setErrors } = useForm<ComposeValues>({
  validationSchema: toTypedSchema(composeSchema),
  initialValues: {
    mailAccountId: props.draft.mailAccountId ?? '',
    to: props.draft.to,
    cc: props.draft.cc,
    bcc: props.draft.bcc,
    subject: props.draft.subject,
    html: props.draft.html,
  },
})
const [mailAccountId] = defineField('mailAccountId')
const [to] = defineField('to')
const [cc] = defineField('cc')
const [bcc] = defineField('bcc')
const [subject] = defineField('subject')
const [html] = defineField('html')

// Every edit goes back to the draft, which saves itself on this device.
watch(
  values,
  (current) => {
    compose.update({
      mailAccountId: current.mailAccountId === '' ? null : (current.mailAccountId ?? null),
      to: current.to ?? [],
      cc: current.cc ?? [],
      bcc: current.bcc ?? [],
      subject: current.subject ?? '',
      html: current.html ?? '',
    })
  },
  { deep: true },
)

// What the API refused lands next to the field.
watch(
  () => compose.violations,
  (violations) => {
    const fieldErrors: Partial<Record<string, string>> = {}
    for (const [path, message] of Object.entries(violations)) {
      fieldErrors[path.replace(/\[\d+\].*$/, '')] = message
    }
    setErrors(fieldErrors)
  },
)

const send = handleSubmit(() => compose.send())

function at(days: number, hour: number): Date {
  const date = new Date()
  date.setDate(date.getDate() + days)
  date.setHours(hour, 0, 0, 0)
  return date
}

const laterOptions = computed(() => {
  const now = new Date()
  const nextMonday = (8 - now.getDay()) % 7 || 7
  const options = [
    { label: 'Tomorrow morning', time: at(1, 8) },
    { label: 'Tomorrow afternoon', time: at(1, 13) },
    { label: 'Monday morning', time: at(nextMonday, 8) },
  ]
  if (now.getHours() < 17) options.unshift({ label: 'This evening', time: at(0, 18) })
  return options
})

const sendLater = (time: Date) =>
  handleSubmit(() => {
    laterOpen.value = false
    return compose.send(time)
  })()

function sendCustom(): void {
  const time = new Date(customTime.value)
  if (Number.isNaN(time.getTime()) || time.getTime() < Date.now()) {
    timeError.value = 'Pick a time in the future.'
    return
  }
  timeError.value = null
  void sendLater(time)
}

function keydown(event: KeyboardEvent): void {
  if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
    event.preventDefault()
    void send()
  }
}
</script>

<template>
  <form class="body" novalidate @submit.prevent="send" @keydown="keydown">
    <div class="field-row" :class="{ invalid: errors.mailAccountId }">
      <label for="compose-from" class="label">From</label>
      <select id="compose-from" v-model="mailAccountId">
        <option v-if="!mailAccountId" value="">Choose an account</option>
        <option v-for="account in mail.accounts" :key="account.id" :value="account.id">{{ account.displayName }} &lt;{{ account.emailAddress }}&gt;</option>
      </select>
    </div>
    <small v-if="errors.mailAccountId" class="field-error">{{ errors.mailAccountId }}</small>
    <RecipientField v-model="to" label="To" :error="errors.to" />
    <button v-if="!showCopies" type="button" class="copies" @click="showCopies = true">Add Cc or Bcc</button>
    <template v-else>
      <RecipientField v-model="cc" label="Cc" :error="errors.cc" />
      <RecipientField v-model="bcc" label="Bcc" :error="errors.bcc" />
    </template>
    <div class="field-row" :class="{ invalid: errors.subject }">
      <label for="compose-subject" class="label">Subject</label>
      <input id="compose-subject" v-model="subject" class="subject" maxlength="998" />
    </div>
    <small v-if="errors.subject" class="field-error">{{ errors.subject }}</small>

    <RichEditor v-model="html" @submit="send" />
    <small v-if="errors.html" class="field-error">{{ errors.html }}</small>

    <p v-if="compose.error" class="form-error" role="alert">{{ compose.error }}</p>

    <footer class="actions">
      <button type="submit" class="button button-primary" :disabled="compose.sending">Send <kbd>⌘ Enter</kbd></button>
      <div class="later">
        <button type="button" class="button button-quiet" :aria-expanded="laterOpen" @click="laterOpen = !laterOpen">Send later</button>
        <div v-if="laterOpen" class="menu" role="menu">
          <button v-for="option in laterOptions" :key="option.label" type="button" role="menuitem" @click="sendLater(option.time)">
            <span>{{ option.label }}</span>
            <span class="when">{{ option.time.toLocaleString(undefined, { weekday: 'short', hour: '2-digit', minute: '2-digit' }) }}</span>
          </button>
          <div class="custom">
            <label for="compose-later">Pick date and time</label>
            <input id="compose-later" v-model="customTime" type="datetime-local" />
            <small v-if="timeError" class="field-error">{{ timeError }}</small>
            <button type="button" class="button button-quiet" @click="sendCustom">Schedule</button>
          </div>
        </div>
      </div>
      <span class="spacer" />
      <button type="button" class="button button-quiet" @click="compose.discard()">Discard</button>
    </footer>
  </form>
</template>

<style scoped>
.body {
  display: flex;
  flex-direction: column;
  min-height: 0;
  overflow: auto;
  padding: 0 16px 12px;
}
.field-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 0;
  border-bottom: 1px solid var(--line);
}
.field-row.invalid .label {
  color: var(--danger);
}
.label {
  width: 52px;
  color: var(--ink-2);
  font-size: 13px;
}
select,
.subject {
  flex: 1;
  min-width: 0;
  height: 30px;
  border: 0;
  outline: 0;
  background: transparent;
  color: var(--ink);
  font: inherit;
}
.subject {
  font-weight: 600;
}
.field-error {
  padding: 2px 0 2px 62px;
  color: var(--danger);
  font-size: 12px;
}
.copies {
  align-self: flex-start;
  margin: 4px 0;
  border: 0;
  background: none;
  color: var(--ink-2);
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
}
.actions {
  display: flex;
  align-items: center;
  gap: 8px;
  padding-top: 8px;
}
kbd {
  font: 500 11px var(--font-mono);
  padding: 2px 6px;
  border-radius: 4px;
  background: color-mix(in srgb, var(--surface) 25%, transparent);
}
.later {
  position: relative;
}
.menu {
  position: absolute;
  bottom: calc(100% + 6px);
  left: 0;
  z-index: 5;
  display: flex;
  flex-direction: column;
  width: 280px;
  padding: 6px;
  border-radius: var(--radius-card);
  background: var(--surface);
  box-shadow: var(--raised);
}
.menu > button {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  padding: 8px 10px;
  border: 0;
  border-radius: var(--radius-control);
  background: transparent;
  text-align: left;
  cursor: pointer;
}
.menu > button:hover {
  background: var(--ground);
}
.when {
  color: var(--ink-3);
  font-size: 12px;
}
.custom {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 8px 10px 4px;
  border-top: 1px solid var(--line);
  font-size: 12px;
  color: var(--ink-2);
}
.custom input {
  height: 32px;
  padding: 0 8px;
  border: 1px solid var(--line-strong);
  border-radius: var(--radius-control);
  background: var(--surface);
  color: var(--ink);
}
.custom .field-error {
  padding: 0;
}
.spacer {
  flex: 1;
}
</style>
