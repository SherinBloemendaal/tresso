<script setup lang="ts">
import { computed, onBeforeUnmount, watch } from 'vue'
import { useRoute, type RouteLocationRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useMailStore } from '@/stores/mail'
import { useSelectionStore } from '@/stores/selection'
import ConversationList from '@/components/ConversationList.vue'
import MailboxSidebar from '@/components/MailboxSidebar.vue'
import ReadingPane from '@/components/ReadingPane.vue'

const auth = useAuthStore()
const mail = useMailStore()
const route = useRoute()

const accountId = computed(() => (typeof route.params.accountId === 'string' ? route.params.accountId : null))
const smartInboxId = computed(() => (typeof route.params.smartInboxId === 'string' ? route.params.smartInboxId : null))
const threadId = computed(() => (typeof route.params.threadId === 'string' ? route.params.threadId : null))
const title = computed(() => {
  if (smartInboxId.value !== null) {
    return mail.smartInboxes.find((s) => s.id === smartInboxId.value)?.name ?? 'Smart inbox'
  }

  return accountId.value === null ? 'Unified Inbox' : (mail.accounts.find((a) => a.id === accountId.value)?.displayName ?? 'Inbox')
})
const backTo = computed(() => {
  if (smartInboxId.value !== null) {
    return `/smart/${smartInboxId.value}`
  }

  return accountId.value === null ? '/' : `/accounts/${accountId.value}`
})

function linkTo(id: string): RouteLocationRaw {
  if (smartInboxId.value !== null) {
    return { name: 'smart-inbox-thread', params: { smartInboxId: smartInboxId.value, threadId: id } }
  }

  return accountId.value === null
    ? { name: 'unified-thread', params: { threadId: id } }
    : { name: 'account-thread', params: { accountId: accountId.value, threadId: id } }
}

const selection = useSelectionStore()
// A selection belongs to the list it was made in.
watch(
  [accountId, smartInboxId],
  ([account, smartInbox]) => {
    selection.clear()
    void (smartInbox === null ? mail.showMailbox(account) : mail.showSmartInbox(smartInbox))
  },
  { immediate: true },
)
onBeforeUnmount(() => selection.clear())

function signOut(): void {
  void auth.signOut()
}
</script>

<template>
  <div class="shell" :class="{ reading: threadId !== null }">
    <details class="mailboxes" open>
      <summary class="mailboxes-toggle">Mailboxes</summary>
      <MailboxSidebar
        :display-name="auth.me?.displayName ?? ''"
        :email="auth.me?.email ?? ''"
        :accounts="mail.accounts"
        :unread-counts="mail.unreadCounts"
        :mailbox="mail.mailbox"
        :smart-inboxes="mail.smartInboxes"
        :smart-inbox-counts="mail.smartInboxCounts"
        :smart-inbox="mail.smartInbox"
        :live="mail.live"
        @sign-out="signOut"
      />
    </details>

    <ConversationList
      class="conversations"
      :title="title"
      :conversations="mail.conversations"
      :selected-thread-id="threadId"
      :has-more="mail.hasMore"
      :loading="!mail.ready || mail.syncState === 'syncing'"
      :has-accounts="mail.accounts.length > 0"
      :empty-text="smartInboxId === null ? 'Nothing in this inbox.' : 'Nothing in this smart inbox.'"
      :link-to="linkTo"
      @show-more="mail.showMore()"
    />

    <ReadingPane v-if="threadId" :key="threadId" class="reading-pane" :thread-id="threadId" :back-to="backTo" />
    <div v-else class="reading-pane placeholder">
      <p>Select a conversation to read it.</p>
    </div>
  </div>
</template>

<style scoped>
.shell {
  display: grid;
  grid-template-columns: minmax(220px, 272px) minmax(360px, 520px) minmax(0, 1fr);
  height: 100%;
  min-height: 0;
}
.mailboxes {
  display: contents;
}
.mailboxes-toggle {
  display: none;
}
.conversations,
.reading-pane,
.mailboxes > :deep(nav) {
  min-height: 0;
}
.placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--surface);
  color: var(--ink-3);
}

@media (max-width: 1100px) {
  .shell {
    grid-template-columns: minmax(220px, 260px) minmax(0, 1fr);
  }
  .shell.reading .conversations,
  .shell:not(.reading) .reading-pane {
    display: none;
  }
}

@media (max-width: 760px) {
  .shell {
    grid-template-columns: minmax(0, 1fr);
    grid-template-rows: auto minmax(0, 1fr);
  }
  .mailboxes {
    display: block;
    border-bottom: 1px solid var(--line);
  }
  .mailboxes-toggle {
    display: block;
    padding: 12px 16px;
    font-weight: 600;
    cursor: pointer;
  }
}
</style>
