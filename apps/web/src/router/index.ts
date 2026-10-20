import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import HomeView from '@/views/HomeView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/', name: 'unified', component: HomeView },
    { path: '/inbox/:threadId', name: 'unified-thread', component: HomeView },
    // Where notifications lead (mailNotificationPath): the conversation, in the Unified Inbox.
    { path: '/thread/:threadId', redirect: (to) => ({ name: 'unified-thread', params: { threadId: to.params.threadId } }) },
    { path: '/accounts/:accountId', name: 'account', component: HomeView },
    { path: '/accounts/:accountId/:threadId', name: 'account-thread', component: HomeView },
    { path: '/smart/:smartInboxId', name: 'smart-inbox', component: HomeView },
    { path: '/smart/:smartInboxId/:threadId', name: 'smart-inbox-thread', component: HomeView },
    { path: '/search', name: 'search', component: () => import('@/views/SearchView.vue'), meta: { requiresAuth: true } },
    { path: '/todos', name: 'todos', component: () => import('@/views/TodosView.vue'), meta: { requiresAuth: true } },
    { path: '/todos/:todoId', name: 'todo', component: () => import('@/views/TodosView.vue'), meta: { requiresAuth: true } },
    { path: '/drafts', name: 'drafts', component: () => import('@/views/DraftsView.vue'), meta: { requiresAuth: true } },
    { path: '/outbox', name: 'outbox', component: () => import('@/views/OutboxView.vue'), meta: { requiresAuth: true } },
    {
      path: '/settings',
      component: () => import('@/views/SettingsLayout.vue'),
      meta: { requiresAuth: true },
      children: [
        { path: '', redirect: { name: 'settings-accounts' } },
        { path: 'accounts', name: 'settings-accounts', component: () => import('@/views/SettingsAccountsView.vue') },
        { path: 'accounts/new', name: 'settings-accounts-new', component: () => import('@/views/AddAccountView.vue') },
        { path: 'signatures', name: 'settings-signatures', component: () => import('@/views/SettingsSignaturesView.vue') },
        { path: 'smart-inboxes', name: 'settings-smart-inboxes', component: () => import('@/views/SettingsSmartInboxesView.vue') },
        { path: 'smart-inboxes/:smartInboxId', name: 'settings-smart-inbox', component: () => import('@/views/SettingsSmartInboxEditView.vue') },
        { path: 'notifications', name: 'settings-notifications', component: () => import('@/views/SettingsNotificationsView.vue') },
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
