<script setup lang="ts">
import { useRequired } from '@/forms/required'

/** A form label whose required mark comes from the form's validation schema (see provideRequiredFields). */
const props = defineProps<{ name?: string; for?: string; tag?: 'label' | 'span' | 'legend' }>()
const required = useRequired(props.name)
</script>

<template>
  <component :is="tag ?? 'span'" :for="tag === 'label' ? props.for : undefined" class="field-label"
    ><slot /><span v-if="required" class="required" aria-hidden="true"> *</span
    ><span v-if="required" class="visually-hidden"> (required)</span></component
  >
</template>

<style scoped>
.required {
  color: var(--danger);
}
</style>
