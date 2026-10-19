import { createApp } from 'vue'
import { createPinia } from 'pinia'
import '@fontsource/instrument-sans/400.css'
import '@fontsource/instrument-sans/500.css'
import '@fontsource/instrument-sans/600.css'
import '@fontsource/bricolage-grotesque/700.css'
import '@fontsource/jetbrains-mono/400.css'
import './assets/main.css'

import App from './App.vue'
import router from './router'
import { config } from './config'
import { isDesktop, onDeepLinks } from './platform/desktop'
import { signInRoute } from './platform/signInLinks'
import { useAuthStore } from './stores/auth'

const app = createApp(App)

app.use(createPinia())
app.use(router)

// The desktop app reads the previous sign-in from the keychain before the first screen, so it never flashes the
// sign-in screen, and takes sign-in results from the system browser as `tresso://` links.
await useAuthStore().restore()
if (isDesktop) {
  await onDeepLinks((link) => {
    const route = signInRoute(link, config.redirectUri)
    if (route !== null) {
      void router.replace(route)
    }
  })
}

app.mount('#app')
