<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import type { SyncedMailAccount } from '@tresso/core'
import AppIcon from '@/components/AppIcon.vue'
import SenderAvatar from '@/components/SenderAvatar.vue'
import { useComposeStore } from '@/stores/compose'
import { useMailStore } from '@/stores/mail'

const props = defineProps<{
  displayName: string
  email: string
  accounts: SyncedMailAccount[]
  unreadCounts: Map<string, number>
  mailbox: string | null
  live: boolean
}>()
const emit = defineEmits<{ signOut: [] }>()

const mail = useMailStore()
const compose = useComposeStore()
const outboxCount = computed(() => mail.outbox.length)
const failedCount = computed(() => mail.outbox.filter((message) => message.status === 'failed').length)

const totalUnread = computed(() => [...props.unreadCounts.values()].reduce((sum, count) => sum + count, 0))

const statusText: Record<SyncedMailAccount['status'], string | null> = {
  active: null,
  paused: 'Paused',
  auth_failed: 'Sign-in failed',
  error: 'Sync problem',
}
</script>

<template>
  <nav class="sidebar" aria-label="Mailboxes">
    <div class="profile">
      <SenderAvatar :sender="{ name: displayName, address: email }" :size="28" />
      <span class="profile-text">
        <span class="profile-name">{{ displayName }}</span>
        <span class="profile-detail">{{ accounts.length === 1 ? '1 account' : `${accounts.length} accounts` }}</span>
      </span>
      <button type="button" class="button button-quiet sign-out" @click="emit('signOut')">Sign out</button>
    </div>

    <button type="button" class="button button-primary compose" @click="compose.openNew()">Compose <kbd>C</kbd></button>

    <div class="group">
      <RouterLink :to="{ name: 'unified' }" class="item" :aria-current="mailbox === null ? 'page' : undefined">
        <AppIcon name="inbox" />
        <span class="label">Unified Inbox</span>
        <span v-if="totalUnread > 0" class="count">{{ totalUnread }}<span class="visually-hidden"> unread</span></span>
      </RouterLink>
      <RouterLink :to="{ name: 'drafts' }" class="item">
        <AppIcon name="draft" />
        <span class="label">Drafts</span>
      </RouterLink>
      <RouterLink v-if="outboxCount > 0" :to="{ name: 'outbox' }" class="item">
        <AppIcon name="outbox" />
        <span class="label">Outbox</span>
        <span class="count" :class="{ failed: failedCount > 0 }">{{ outboxCount }}</span>
      </RouterLink>
    </div>

    <div class="group">
      <h2 class="group-title">Accounts</h2>
      <RouterLink
        v-for="account in accounts"
        :key="account.id"
        :to="{ name: 'account', params: { accountId: account.id } }"
        class="item account"
        :aria-current="mailbox === account.id ? 'page' : undefined"
      >
        <SenderAvatar :sender="{ name: account.displayName, address: account.emailAddress }" :size="24" />
        <span class="account-text">
          <span class="account-name">{{ account.displayName }}</span>
          <span class="account-address">{{ statusText[account.status] ?? account.emailAddress }}</span>
        </span>
        <span v-if="(unreadCounts.get(account.id) ?? 0) > 0" class="count">{{ unreadCounts.get(account.id) }}<span class="visually-hidden"> unread</span></span>
      </RouterLink>
      <RouterLink :to="{ name: 'settings-accounts-new' }" class="add">+ Add account</RouterLink>
    </div>

    <RouterLink :to="{ name: 'settings-accounts' }" class="item settings">Settings</RouterLink>

    <p class="live" :class="{ on: live }" role="status">{{ live ? 'Up to date' : 'Connecting…' }}</p>
  </nav>
</template>

<style scoped>
.sidebar {
  display: flex;
  flex-direction: column;
  gap: 18px;
  padding: 20px 12px;
  min-height: 0;
  overflow-y: auto;
}
.profile {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 4px 0 10px;
  min-height: 44px;
}
.profile-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}
.profile-name {
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.profile-detail,
.account-address {
  font-size: 12px;
  color: var(--ink-2);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.sign-out {
  height: 32px;
  padding: 0 8px;
}
.compose {
  height: 40px;
  margin: 0 4px;
}
.compose kbd {
  font: 500 11px var(--font-mono);
  padding: 2px 6px;
  border-radius: 4px;
  background: color-mix(in srgb, var(--surface) 25%, transparent);
}
.count.failed {
  color: var(--danger);
  font-weight: 700;
}
.group {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.group-title {
  margin: 0;
  padding: 0 10px 6px;
  font-size: 12px;
  font-weight: 600;
  color: var(--ink-2);
}
.item {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 36px;
  padding: 0 10px;
  border-radius: var(--radius-control);
  color: var(--ink);
  font-weight: 500;
  text-decoration: none;
}
.item:hover {
  background: color-mix(in srgb, var(--surface) 60%, transparent);
}
.item[aria-current='page'] {
  background: var(--surface);
  box-shadow: 0 1px 2px rgb(22 24 29 / 6%);
  font-weight: 600;
}
.account {
  min-height: 44px;
  padding: 4px 10px;
}
.account-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}
.label {
  flex: 1;
}
.count {
  font-size: 12px;
  color: var(--ink-2);
}
.add {
  display: flex;
  align-items: center;
  height: 32px;
  padding: 0 10px;
  color: var(--ink-2);
  font-size: 13px;
  font-weight: 600;
  text-decoration: none;
}
.settings {
  margin-top: auto;
}
.live {
  margin: 0;
  padding: 0 10px;
  font-size: 12px;
  color: var(--ink-3);
}
.live::before {
  content: '';
  display: inline-block;
  width: 6px;
  height: 6px;
  margin-right: 6px;
  border-radius: 999px;
  background: var(--ink-3);
  vertical-align: middle;
}
.live.on::before {
  background: var(--know);
}
</style>
