<script setup lang="ts">
import { ref, watch } from 'vue'
import type { SyncedMessage, SyncedThread } from '@tresso/core'
import { useMailStore } from '@/stores/mail'
import MessageCard from '@/components/MessageCard.vue'

const props = defineProps<{ threadId: string; backTo: string }>()

const mail = useMailStore()
const thread = ref<SyncedThread | null>(null)
const messages = ref<SyncedMessage[]>([])
const expanded = ref(new Set<string>())
const missing = ref(false)

async function load(): Promise<void> {
  const result = await mail.thread(props.threadId)
  missing.value = result === null
  thread.value = result?.thread ?? null
  messages.value = result?.messages ?? []
}

watch(
  () => props.threadId,
  async () => {
    await load()
    // Unread messages and the newest one open; read history stays folded.
    const open = new Set(messages.value.filter((message) => !message.flags.seen).map((message) => message.id))
    const newest = messages.value[messages.value.length - 1]
    if (newest) {
      open.add(newest.id)
    }
    expanded.value = open
  },
  { immediate: true },
)

// New mail in this thread arrives through sync; reload so it shows up.
watch(() => mail.conversations, load)

function toggle(messageId: string): void {
  const next = new Set(expanded.value)
  if (!next.delete(messageId)) {
    next.add(messageId)
  }
  expanded.value = next
}
</script>

<template>
  <article class="pane" aria-label="Conversation">
    <p v-if="missing" class="missing">This conversation no longer exists. <RouterLink :to="backTo">Back to the inbox</RouterLink></p>
    <div v-else-if="thread" class="inner">
      <h2 class="subject">{{ thread.subject || '(no subject)' }}</h2>
      <MessageCard
        v-for="message in messages"
        :key="message.id"
        :message="message"
        :expanded="expanded.has(message.id)"
        @toggle="toggle(message.id)"
      />
    </div>
  </article>
</template>

<style scoped>
.pane {
  min-height: 0;
  overflow-y: auto;
  background: var(--surface);
}
.inner {
  max-width: 860px;
  padding: 32px 40px;
}
.subject {
  margin: 0 0 8px;
  font-size: 26px;
  line-height: 34px;
  font-weight: 600;
}
.missing {
  padding: 32px 40px;
  color: var(--ink-2);
}
</style>
