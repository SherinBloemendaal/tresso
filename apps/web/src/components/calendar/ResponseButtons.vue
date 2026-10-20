<script setup lang="ts">
import type { ParticipationStatus } from '@tresso/core'
import type { Response } from '@/stores/calendar'

/** Accept, Maybe, Decline: the answer to an invitation, with the current one pressed. */
defineProps<{ current: ParticipationStatus | null; busy?: boolean; label: string; shortcuts?: boolean }>()
const emit = defineEmits<{ respond: [response: Response] }>()

const OPTIONS: { value: Response; label: string; key: string }[] = [
  { value: 'accepted', label: 'Accept', key: 'Y' },
  { value: 'tentative', label: 'Maybe', key: 'M' },
  { value: 'declined', label: 'Decline', key: 'N' },
]
</script>

<template>
  <div class="responses" role="group" :aria-label="label">
    <button
      v-for="option in OPTIONS"
      :key="option.value"
      type="button"
      class="response"
      :class="option.value"
      :aria-pressed="current === option.value ? 'true' : 'false'"
      :disabled="busy"
      @click="emit('respond', option.value)"
    >
      {{ option.label }}<kbd v-if="shortcuts">{{ option.key }}</kbd>
    </button>
  </div>
</template>

<style scoped>
.responses {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}
.response {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 32px;
  padding: 0 12px;
  border: 1px solid var(--line-strong);
  border-radius: var(--radius-control);
  background: var(--surface);
  color: var(--ink);
  font: 600 13px var(--font-ui);
  cursor: pointer;
}
.response[aria-pressed='true'] {
  background: var(--ink);
  border-color: var(--ink);
  color: var(--surface);
}
.response:disabled {
  opacity: 0.55;
  cursor: default;
}
kbd {
  font: 500 11px var(--font-mono);
  opacity: 0.7;
}
</style>
