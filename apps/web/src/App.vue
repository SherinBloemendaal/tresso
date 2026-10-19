<script setup lang="ts">
import { defineAsyncComponent, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterView } from 'vue-router'
import { errorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useComposeStore } from '@/stores/compose'
import { useMailStore } from '@/stores/mail'
import { startTimeZoneSync } from '@/platform/timeZone'
import NoticeStack from '@/components/NoticeStack.vue'

const auth = useAuthStore()
const mail = useMailStore()
const compose = useComposeStore()
// The editor is large; it loads the first time a message is written.
const ComposeWindow = defineAsyncComponent(() => import('@/components/ComposeWindow.vue'))
const error = ref<string | null>(null)

// "C" writes a new message, unless the user is typing somewhere.
function shortcut(event: KeyboardEvent): void {
  const target = event.target as HTMLElement | null
  const typing = target !== null && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))
  if (!typing && auth.isAuthenticated && !event.metaKey && !event.ctrlKey && !event.altKey && event.key.toLowerCase() === 'c' && compose.draft === null) {
    event.preventDefault()
    compose.openNew()
  }
}
onMounted(() => window.addEventListener('keydown', shortcut))
onBeforeUnmount(() => window.removeEventListener('keydown', shortcut))

// Reminders are planned in the zone of the device the user last brought to the front.
let stopTimeZone: (() => void) | null = null
onBeforeUnmount(() => stopTimeZone?.())

// The mail copy runs for the whole session, so every screen (inbox, settings) sees live changes.
watch(
  () => auth.isAuthenticated,
  async (authenticated) => {
    stopTimeZone?.()
    stopTimeZone = null
    if (!authenticated) {
      return
    }
    stopTimeZone = startTimeZoneSync(auth.api, (e) => compose.notify({ text: `Reminders may come at the wrong time: your time zone could not be saved (${errorMessage(e)}).`, tone: 'error' }))
    try {
      await auth.loadMe()
      if (auth.me) {
        await mail.start(auth.me.id)
      }
      error.value = null
    } catch (e) {
      error.value = errorMessage(e)
    }
  },
  { immediate: true },
)
</script>

<template>
  <RouterView />
  <template v-if="auth.isAuthenticated">
    <ComposeWindow v-if="compose.draft" />
    <NoticeStack />
  </template>
  <p v-if="error || mail.syncState === 'error'" class="problem" role="alert">
    {{ error ?? `Sync stopped: ${mail.syncError}` }}
  </p>
</template>

<style scoped>
.problem {
  position: fixed;
  left: 50%;
  bottom: 16px;
  transform: translateX(-50%);
  margin: 0;
  padding: 10px 16px;
  border-radius: var(--radius-card);
  background: var(--ink);
  color: var(--surface);
  box-shadow: var(--raised);
}
</style>
