import { createRouter, createWebHistory } from 'vue-router'
import HomeView from '@/views/HomeView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/', name: 'unified', component: HomeView },
    { path: '/inbox/:threadId', name: 'unified-thread', component: HomeView },
    { path: '/accounts/:accountId', name: 'account', component: HomeView },
    { path: '/accounts/:accountId/:threadId', name: 'account-thread', component: HomeView },
    { path: '/auth/callback', name: 'auth-callback', component: () => import('@/views/AuthCallbackView.vue') },
  ],
})

export default router
