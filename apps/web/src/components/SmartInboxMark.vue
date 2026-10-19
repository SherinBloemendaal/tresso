<script setup lang="ts">
import { computed } from 'vue'
import { smartInboxMark, type SyncedSmartInbox } from '@tresso/core'

/**
 * The square letter mark of a smart inbox. The built-in To do and Informational carry their verdict's color and Spam
 * the danger color; every other smart inbox stays grey, so color keeps meaning something.
 */
const props = defineProps<{ smartInbox: Pick<SyncedSmartInbox, 'builtin' | 'name'> }>()

const tone = computed(() => {
  switch (props.smartInbox.builtin) {
    case 'todo':
      return 'do'
    case 'informational':
      return 'know'
    case 'spam':
      return 'danger'
    default:
      return 'plain'
  }
})
</script>

<template>
  <span class="mark" :class="tone" aria-hidden="true">{{ smartInboxMark(smartInbox) }}</span>
</template>

<style scoped>
.mark {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  border-radius: var(--radius-control);
  font-size: 11px;
  font-weight: 700;
}
.plain {
  background: var(--line);
  color: var(--ink-2);
}
.do {
  background: var(--do-soft);
  color: var(--do);
}
.know {
  background: var(--know-soft);
  color: var(--know);
}
.danger {
  background: var(--danger-soft);
  color: var(--danger);
}
</style>
