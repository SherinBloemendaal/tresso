import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import HomeView from '@/views/HomeView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/', name: 'unified', component: HomeView },
    { path: '/inbox/:threadId', name: 'unified-thread', component: HomeView },
    { path: '/accounts/:accountId', name: 'account', component: HomeView },
    { path: '/accounts/:accountId/:threadId', name: 'account-thread', component: HomeView },
    { path: '/outbox', name: 'outbox', component: () => import('@/views/OutboxView.vue'), meta: { requiresAuth: true } },
    {
      path: '/settings',
      component: () => import('@/views/SettingsLayout.vue'),
      meta: { requiresAuth: true },
      children: [
        { path: '', redirect: { name: 'settings-accounts' } },
        { path: 'accounts', name: 'settings-accounts', component: () => import('@/views/SettingsAccountsView.vue') },
        { path: 'accounts/new', name: 'settings-accounts-new', component: () => import('@/views/AddAccountView.vue') },
      ],
    },
    { path: '/auth/callback', name: 'auth-callback', component: () => import('@/views/AuthCallbackView.vue') },
  ],
})

// Signed-out visitors land on the sign-in screen.
router.beforeEach((to) => {
  if (to.matched.some((record) => record.meta.requiresAuth) && !useAuthStore().isAuthenticated) {
    return { name: 'unified' }
  }
  return true
})

export default router
