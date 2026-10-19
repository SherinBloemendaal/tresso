<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import type { MessageAction, SyncedFolder, SyncedMessage, SyncedThread } from '@tresso/core'
import { errorMessage } from '@/api/errors'
import { useComposeStore } from '@/stores/compose'
import { useMailStore } from '@/stores/mail'
import AppIcon from '@/components/AppIcon.vue'
import MessageCard from '@/components/MessageCard.vue'

const props = defineProps<{ threadId: string; backTo: string }>()

const mail = useMailStore()
const compose = useComposeStore()
const router = useRouter()
const thread = ref<SyncedThread | null>(null)
const messages = ref<SyncedMessage[]>([])
const folders = ref<SyncedFolder[]>([])
const expanded = ref(new Set<string>())
const missing = ref(false)
const moveTarget = ref('')

const flagged = computed(() => messages.value.some((message) => message.flags.flagged))
// Folders the conversation can move to: its account's, except the ones mail apps manage themselves.
const moveTargets = computed(() =>
  folders.value
    .filter((folder) => folder.mailAccountId === thread.value?.mailAccountId && folder.role !== 'drafts' && folder.role !== 'all')
    .sort((a, b) => a.displayName.localeCompare(b.displayName)),
)

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
    folders.value = await mail.folders()
    // Unread messages and the newest one open; read history stays folded.
    const open = new Set(messages.value.filter((message) => !message.flags.seen).map((message) => message.id))
    const newest = messages.value[messages.value.length - 1]
    if (newest) {
      open.add(newest.id)
    }
    expanded.value = open
    // Opening a conversation reads it.
    const unread = messages.value.filter((message) => !message.flags.seen).map((message) => message.id)
    if (unread.length > 0) {
      void run(unread, 'markRead')
    }
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

async function run(messageIds: string[], action: MessageAction, folderId?: string): Promise<boolean> {
  try {
    await mail.act(messageIds, action, folderId)
    await load()
    return true
  } catch (error) {
    compose.notify({ text: errorMessage(error), tone: 'error' })
    return false
  }
}

/**
 * Archives, trashes or moves the whole conversation and goes back to the list, with an undo that puts every message
 * back in the folder it came from.
 */
async function relocate(action: 'archive' | 'trash' | 'move', folderId?: string): Promise<void> {
  const before = messages.value.map((message) => ({ id: message.id, folderId: message.folderIds[0] }))
  if (!(await run(before.map((entry) => entry.id), action, folderId))) {
    return
  }
  const label = action === 'archive' ? 'Archived' : action === 'trash' ? 'Moved to Trash' : `Moved to ${folders.value.find((folder) => folder.id === folderId)?.displayName ?? 'the folder'}`
  compose.notify({
    text: `${label}.`,
    tone: 'info',
    action: {
      label: 'Undo',
      run: () => void undo(before),
    },
  })
  await router.push(props.backTo)
}

async function undo(before: { id: string; folderId: string | undefined }[]): Promise<void> {
  const byFolder = new Map<string, string[]>()
  for (const entry of before) {
    if (entry.folderId !== undefined) {
      byFolder.set(entry.folderId, [...(byFolder.get(entry.folderId) ?? []), entry.id])
    }
  }
  try {
    for (const [folderId, ids] of byFolder) {
      await mail.act(ids, 'move', folderId)
    }
  } catch (error) {
    compose.notify({ text: errorMessage(error), tone: 'error' })
  }
}

async function markUnread(): Promise<void> {
  const newest = messages.value[messages.value.length - 1]
  if (newest && (await run([newest.id], 'markUnread'))) {
    await router.push(props.backTo)
  }
}

async function toggleFlag(): Promise<void> {
  if (flagged.value) {
    await run(messages.value.filter((message) => message.flags.flagged).map((message) => message.id), 'unflag')
    return
  }
  const newest = messages.value[messages.value.length - 1]
  if (newest) {
    await run([newest.id], 'flag')
  }
}

async function moveTo(): Promise<void> {
  const folderId = moveTarget.value
  moveTarget.value = ''
  if (folderId !== '') {
    await relocate('move', folderId)
  }
}

// E archives, # trashes, U marks unread, S flags, unless the user is typing somewhere.
function shortcut(event: KeyboardEvent): void {
  const target = event.target as HTMLElement | null
  const typing = target !== null && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))
  if (typing || event.metaKey || event.ctrlKey || event.altKey || thread.value === null) {
    return
  }
  const action = { e: () => relocate('archive'), '#': () => relocate('trash'), u: markUnread, s: toggleFlag }[event.key.toLowerCase()]
  if (action !== undefined) {
    event.preventDefault()
    void action()
  }
}
onMounted(() => window.addEventListener('keydown', shortcut))
onBeforeUnmount(() => window.removeEventListener('keydown', shortcut))
</script>

<template>
  <article class="pane" aria-label="Conversation">
    <p v-if="missing" class="missing">This conversation no longer exists. <RouterLink :to="backTo">Back to the inbox</RouterLink></p>
    <div v-else-if="thread" class="inner">
      <div class="toolbar" role="toolbar" aria-label="Conversation actions">
        <button type="button" class="tool" aria-keyshortcuts="E" title="Archive (E)" @click="relocate('archive')"><AppIcon name="archive" label="Archive" /></button>
        <button type="button" class="tool" aria-keyshortcuts="#" title="Move to Trash (#)" @click="relocate('trash')"><AppIcon name="trash" label="Move to Trash" /></button>
        <button type="button" class="tool" aria-keyshortcuts="U" title="Mark unread (U)" @click="markUnread"><AppIcon name="unread" label="Mark unread" /></button>
        <button type="button" class="tool" :class="{ on: flagged }" :aria-pressed="flagged" aria-keyshortcuts="S" :title="flagged ? 'Remove flag (S)' : 'Flag (S)'" @click="toggleFlag">
          <AppIcon name="flag" :label="flagged ? 'Remove flag' : 'Flag'" />
        </button>
        <label class="move">
          <AppIcon name="folder" />
          <select v-model="moveTarget" aria-label="Move to folder" @change="moveTo">
            <option value="" disabled>Move to…</option>
            <option v-for="folder in moveTargets" :key="folder.id" :value="folder.id">{{ folder.displayName }}</option>
          </select>
        </label>
      </div>
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
  padding: 24px 40px 32px;
}
.toolbar {
  display: flex;
  align-items: center;
  gap: 4px;
  margin-bottom: 16px;
}
.tool {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border: 0;
  border-radius: var(--radius-control);
  background: transparent;
  color: var(--ink-2);
  cursor: pointer;
}
.tool:hover {
  background: var(--ground);
  color: var(--ink);
}
.tool.on {
  color: var(--delegate);
}
.move {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-left: 8px;
  padding: 0 8px;
  height: 36px;
  border-radius: var(--radius-control);
  color: var(--ink-2);
}
.move select {
  border: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  cursor: pointer;
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
