<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'
import TressoMark from '@/components/TressoMark.vue'
import MailView from '@/views/MailView.vue'

const auth = useAuthStore()
</script>

<template>
  <MailView v-if="auth.isAuthenticated" />
  <main v-else class="welcome">
    <TressoMark :size="56" />
    <h1>Every email is something to do, to hand over, or to know.</h1>
    <button type="button" class="button button-primary sign-in" @click="auth.signIn()">Sign in</button>
  </main>
</template>

<style scoped>
.welcome {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 24px;
  min-height: 100%;
  padding: 32px 16px;
  text-align: center;
}
h1 {
  max-width: 640px;
  margin: 0;
  font: 700 40px/46px var(--font-display);
  letter-spacing: -0.02em;
}
.sign-in {
  height: 44px;
  padding: 0 24px;
  font-size: 15px;
}
</style>
