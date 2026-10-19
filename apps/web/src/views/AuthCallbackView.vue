<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const error = ref<string | null>(null)

onMounted(async () => {
  try {
    await auth.completeSignIn(window.location.href)
    await router.replace({ name: 'home' })
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  }
})
</script>

<template>
  <main>
    <p v-if="error" role="alert">
      Sign-in failed: {{ error }}
      <RouterLink :to="{ name: 'home' }">Try again</RouterLink>
    </p>
    <p v-else>Signing you in…</p>
  </main>
</template>
