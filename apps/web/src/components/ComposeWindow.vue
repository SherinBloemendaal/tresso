<script setup lang="ts">
import { computed } from 'vue'
import { useComposeStore } from '@/stores/compose'
import ComposeForm from '@/components/ComposeForm.vue'

const compose = useComposeStore()

const title = computed(() => {
  switch (compose.draft?.kind) {
    case 'reply':
    case 'replyAll':
      return 'Reply'
    case 'forward':
      return 'Forward'
    default:
      return 'New message'
  }
})
const savedText = computed(() =>
  compose.savedAt === null ? '' : `Draft saved on this device ${new Date(compose.savedAt).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })}`,
)
</script>

<template>
  <section v-if="compose.draft" class="compose" :class="{ minimized: compose.minimized }" role="dialog" :aria-label="title" @keydown.esc="compose.close()">
    <header class="bar">
      <h2>{{ title }}</h2>
      <span class="saved" aria-live="polite">{{ savedText }}</span>
      <button type="button" class="icon" :aria-label="compose.minimized ? 'Expand' : 'Minimize'" @click="compose.minimized = !compose.minimized">
        {{ compose.minimized ? '▴' : '▾' }}
      </button>
      <button type="button" class="icon" aria-label="Close and keep the draft" @click="compose.close()">×</button>
    </header>
    <ComposeForm v-show="!compose.minimized" :key="compose.draft.id" :draft="compose.draft" />
  </section>
</template>

<style scoped>
.compose {
  position: fixed;
  right: 24px;
  bottom: 0;
  z-index: 20;
  display: flex;
  flex-direction: column;
  width: min(640px, calc(100vw - 32px));
  max-height: calc(100vh - 48px);
  border-radius: var(--radius-panel) var(--radius-panel) 0 0;
  background: var(--surface);
  box-shadow: var(--raised);
}
.bar {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px 10px 16px;
  border-bottom: 1px solid var(--line);
}
.bar h2 {
  margin: 0;
  font-size: 14px;
  font-weight: 600;
}
.saved {
  flex: 1;
  font-size: 12px;
  color: var(--ink-3);
}
.icon {
  width: 28px;
  height: 28px;
  border: 0;
  border-radius: var(--radius-control);
  background: transparent;
  color: var(--ink-2);
  cursor: pointer;
  font-size: 16px;
}
.icon:hover {
  background: var(--ground);
}
.minimized {
  width: 320px;
}
@media (max-width: 760px) {
  .compose {
    inset: 0;
    width: 100%;
    max-height: none;
    border-radius: 0;
  }
}
</style>
