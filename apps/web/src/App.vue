<script setup lang="ts">
import { ref, watch } from 'vue'
import { RouterView } from 'vue-router'
import { errorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useMailStore } from '@/stores/mail'

const auth = useAuthStore()
const mail = useMailStore()
const error = ref<string | null>(null)

// The mail copy runs for the whole session, so every screen (inbox, settings) sees live changes.
watch(
  () => auth.isAuthenticated,
  async (authenticated) => {
    if (!authenticated) {
      return
    }
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
