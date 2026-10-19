<script setup lang="ts">
import { verdictLabel, type Verdict } from '@tresso/core'
import AppIcon from '@/components/AppIcon.vue'

/** A verdict in its own color, the only place in a list where color shows up. */
defineProps<{ verdict: Verdict }>()
</script>

<template>
  <span class="verdict" :class="verdict"><AppIcon :name="verdict" :size="14" />{{ verdictLabel(verdict) }}</span>
</template>

<style scoped>
.verdict {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 24px;
  padding: 0 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
}
.do {
  background: var(--do-soft);
  color: var(--do);
}
.delegate {
  background: var(--delegate-soft);
  color: var(--delegate);
}
.know {
  background: var(--know-soft);
  color: var(--know);
}
.none {
  background: var(--ground);
  color: var(--ink-2);
}
</style>
