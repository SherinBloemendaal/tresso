<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const error = ref<string | null>(null)

onMounted(async () => {
  if (!auth.isAuthenticated) {
    return
  }
  try {
    await auth.loadMe()
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  }
})
</script>

<template>
  <main class="home">
    <template v-if="auth.me">
      <h1>Hi {{ auth.me.displayName }}</h1>
      <p>Signed in as {{ auth.me.email }}.</p>
      <button type="button" @click="auth.signOut()">Sign out</button>
    </template>
    <template v-else-if="!auth.isAuthenticated">
      <h1>Every email is something to do, to hand over, or to know.</h1>
      <button type="button" @click="auth.signIn()">Sign in</button>
    </template>
    <p v-if="error" role="alert">{{ error }}</p>
  </main>
</template>
