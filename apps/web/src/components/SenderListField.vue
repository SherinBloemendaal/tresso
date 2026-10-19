<script setup lang="ts">
import { ref } from 'vue'
import { normalizeSenderPattern } from '@tresso/core'
import AppIcon from '@/components/AppIcon.vue'

/** A list of addresses and @domains as removable chips, with an input that adds on Enter, comma or leaving it. */
const props = defineProps<{ id: string; modelValue: string[]; invalid?: boolean; describedBy?: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>()

const draft = ref('')

function add(): void {
  const entries = draft.value
    .split(/[\s,;]+/)
    .map(normalizeSenderPattern)
    .filter((entry) => entry !== '' && !props.modelValue.includes(entry))
  draft.value = ''
  if (entries.length > 0) {
    emit('update:modelValue', [...props.modelValue, ...new Set(entries)])
  }
}

function remove(entry: string): void {
  emit(
    'update:modelValue',
    props.modelValue.filter((candidate) => candidate !== entry),
  )
}

function keydown(event: KeyboardEvent): void {
  if (event.key === 'Enter' || event.key === ',') {
    event.preventDefault()
    add()
    return
  }
  if (event.key === 'Backspace' && draft.value === '' && props.modelValue.length > 0) {
    remove(props.modelValue[props.modelValue.length - 1]!)
  }
}
</script>

<template>
  <div class="senders" :class="{ invalid }">
    <span v-for="entry in modelValue" :key="entry" class="chip">
      {{ entry }}
      <button type="button" class="remove" :aria-label="`Remove ${entry}`" @click="remove(entry)"><AppIcon name="close" :size="11" /></button>
    </span>
    <input
      :id="id"
      v-model="draft"
      type="text"
      autocomplete="off"
      spellcheck="false"
      placeholder="Add sender or @domain"
      :aria-invalid="invalid ? 'true' : undefined"
      :aria-describedby="describedBy"
      @keydown="keydown"
      @blur="add"
    />
  </div>
</template>

<style scoped>
.senders {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  min-height: 40px;
  padding: 5px 8px;
  border: 1px solid var(--line-strong);
  border-radius: var(--radius-control);
  background: var(--surface);
}
.senders:focus-within {
  outline: 2px solid var(--focus);
  outline-offset: 1px;
}
.senders.invalid {
  border-color: var(--danger);
}
.chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  height: 26px;
  padding: 0 2px 0 10px;
  border-radius: 999px;
  background: var(--ground);
  font: 400 12px var(--font-mono);
  color: var(--ink);
}
.remove {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  border: 0;
  border-radius: 999px;
  background: transparent;
  color: var(--ink-2);
  cursor: pointer;
}
input {
  flex: 1;
  min-width: 140px;
  height: 26px;
  border: 0;
  outline: 0;
  background: transparent;
  color: var(--ink);
  font: 400 13px var(--font-ui);
}
</style>
