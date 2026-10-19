<script setup lang="ts">
import FieldLabel from '@/forms/FieldLabel.vue'
import { useId } from 'vue'
import { defaultPort, type ConnectionSecurity, type ServerSettings } from '@tresso/core'

const props = defineProps<{
  protocol: 'imap' | 'smtp'
  title: string
  /** Validation messages by field: host, port, security. */
  errors?: Record<string, string>
}>()
const settings = defineModel<ServerSettings>({ required: true })
const id = useId()

function setSecurity(security: ConnectionSecurity): void {
  const usual = [defaultPort(props.protocol, 'tls'), defaultPort(props.protocol, 'starttls')]
  // Follow the port along when it still is one of the usual ones.
  const port = usual.includes(settings.value.port) ? defaultPort(props.protocol, security) : settings.value.port
  settings.value = { ...settings.value, security, port }
}
</script>

<template>
  <fieldset class="server">
    <legend>{{ title }} <span class="protocol">{{ protocol.toUpperCase() }}</span></legend>
    <div class="row">
      <label class="field host">
        <FieldLabel :name="`${protocol}.host`">Server</FieldLabel>
        <input
          :id="`${id}-host`"
          :value="settings.host"
          autocomplete="off"
          spellcheck="false"
          required
          :aria-invalid="errors?.host ? 'true' : undefined"
          :aria-describedby="errors?.host ? `${id}-host-error` : undefined"
          @input="settings = { ...settings, host: ($event.target as HTMLInputElement).value.trim() }"
        />
        <small v-if="errors?.host" :id="`${id}-host-error`" class="error">{{ errors.host }}</small>
      </label>
      <label class="field port">
        <FieldLabel :name="`${protocol}.port`">Port</FieldLabel>
        <input
          :value="settings.port"
          type="number"
          min="1"
          max="65535"
          required
          :aria-invalid="errors?.port ? 'true' : undefined"
          @input="settings = { ...settings, port: Number(($event.target as HTMLInputElement).value) }"
        />
        <small v-if="errors?.port" class="error">{{ errors.port }}</small>
      </label>
      <label class="field security">
        <FieldLabel :name="`${protocol}.security`">Security</FieldLabel>
        <select :value="settings.security" @change="setSecurity(($event.target as HTMLSelectElement).value as ConnectionSecurity)">
          <option value="tls">SSL/TLS</option>
          <option value="starttls">STARTTLS</option>
          <option value="none">None (not recommended)</option>
        </select>
        <small v-if="errors?.security" class="error">{{ errors.security }}</small>
      </label>
    </div>
  </fieldset>
</template>

<style scoped>
.server {
  margin: 0;
  padding: 0;
  border: 0;
  min-width: 0;
}
legend {
  padding: 0 0 8px;
  font-weight: 600;
}
.protocol {
  font: 500 11px var(--font-mono);
  color: var(--ink-3);
}
.row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 96px 168px;
  gap: 12px;
}
@media (max-width: 600px) {
  .row {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  }
  .host {
    grid-column: 1 / -1;
  }
}
</style>
