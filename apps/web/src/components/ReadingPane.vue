<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import type { MessageAction, SyncedFolder, SyncedMessage, SyncedThread } from '@tresso/core'
import { errorMessage } from '@/api/errors'
import type { AppliedAction } from '@/mail/db'
import { undoPlan, type UndoMove } from '@/mail/undo'
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
/** An action is on its way; the buttons and shortcuts wait for it. */
const busy = ref(false)
const menuOpen = ref(false)
const menu = ref<HTMLElement | null>(null)
const menuButton = ref<HTMLButtonElement | null>(null)
let unmounted = false

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
  async (threadId) => {
    await load()
    folders.value = await mail.folders()
    if (unmounted || threadId !== props.threadId) {
      return
    }
    // Unread messages and the newest one open; read history stays folded.
    const unread = messages.value.filter((message) => !message.flags.seen).map((message) => message.id)
    const open = new Set(unread)
    const newest = messages.value[messages.value.length - 1]
    if (newest) {
      open.add(newest.id)
    }
    expanded.value = open
    // Opening a conversation reads it.
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

async function run(messageIds: string[], action: MessageAction, folderId?: string): Promise<AppliedAction | null> {
  try {
    const applied = await mail.act(messageIds, action, { folderId })
    if (!unmounted) {
      await load()
    }
    return applied
  } catch (error) {
    compose.notify({ text: errorMessage(error), tone: 'error' })
    return null
  }
}

/** Runs one action at a time: a second click or a held key waits for the first instead of acting on its result. */
async function exclusive(task: () => Promise<void>): Promise<void> {
  if (busy.value) {
    return
  }
  busy.value = true
  try {
    await task()
  } finally {
    busy.value = false
  }
}

/**
 * Archives, trashes or moves the whole conversation and goes back to the list. The undo puts back exactly what moved;
 * when that is not possible (a message that was in several folders), none is offered.
 */
function relocate(action: 'archive' | 'trash' | 'move', folderId?: string): Promise<void> {
  return exclusive(async () => {
    const applied = await run(
      messages.value.map((message) => message.id),
      action,
      folderId,
    )
    if (applied === null) {
      return
    }
    const label = action === 'archive' ? 'Archived' : action === 'trash' ? 'Moved to Trash' : `Moved to ${folders.value.find((folder) => folder.id === folderId)?.displayName ?? 'the folder'}`
    const plan = undoPlan(applied, action, folders.value)
    compose.notify({
      text: `${label}.`,
      tone: 'info',
      action: plan.exact && plan.moves.length > 0 ? { label: 'Undo', run: () => void undo(plan.moves) } : undefined,
    })
    await router.push(props.backTo)
  })
}

async function undo(moves: UndoMove[]): Promise<void> {
  let failed = 0
  let reason = ''
  for (const move of moves) {
    try {
      await mail.act(move.messageIds, 'move', { folderId: move.folderId, fromFolderId: move.fromFolderId })
    } catch (error) {
      failed += move.messageIds.length
      reason = errorMessage(error)
    }
  }
  if (failed > 0) {
    compose.notify({ text: `${failed === 1 ? 'One message' : `${failed} messages`} could not be put back: ${reason}`, tone: 'error' })
  }
}

function markUnread(): Promise<void> {
  return exclusive(async () => {
    const newest = messages.value[messages.value.length - 1]
    if (newest && (await run([newest.id], 'markUnread')) !== null) {
      await router.push(props.backTo)
    }
  })
}

function toggleFlag(): Promise<void> {
  return exclusive(async () => {
    if (flagged.value) {
      await run(
        messages.value.filter((message) => message.flags.flagged).map((message) => message.id),
        'unflag',
      )
      return
    }
    const newest = messages.value[messages.value.length - 1]
    if (newest) {
      await run([newest.id], 'flag')
    }
  })
}

async function openMenu(): Promise<void> {
  menuOpen.value = true
  await nextTick()
  menu.value?.querySelector<HTMLButtonElement>('[role="menuitem"]')?.focus()
}

function closeMenu(): void {
  menuOpen.value = false
  menuButton.value?.focus()
}

function moveTo(folderId: string): void {
  menuOpen.value = false
  void relocate('move', folderId)
}

function menuKeydown(event: KeyboardEvent): void {
  const items = [...(menu.value?.querySelectorAll<HTMLButtonElement>('[role="menuitem"]') ?? [])]
  const index = items.indexOf(document.activeElement as HTMLButtonElement)
  const focus = (next: number) => items[(next + items.length) % items.length]?.focus()
  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault()
      focus(index + 1)
      break
    case 'ArrowUp':
      event.preventDefault()
      focus(index - 1)
      break
    case 'Home':
      event.preventDefault()
      focus(0)
      break
    case 'End':
      event.preventDefault()
      focus(items.length - 1)
      break
    case 'Escape':
    case 'Tab':
      event.preventDefault()
      closeMenu()
      break
  }
}

// E archives, # trashes, U marks unread, S flags: only when the conversation has the user's attention, not while
// they type, write in the compose window, or hold the key down.
function shortcut(event: KeyboardEvent): void {
  const target = event.target instanceof HTMLElement ? event.target : null
  const typing = target !== null && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))
  const composing = (compose.draft !== null && !compose.minimized) || target?.closest('[role="dialog"]') != null
  if (typing || composing || event.repeat || event.isComposing || event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey || thread.value === null || menuOpen.value) {
    return
  }
  const action = { e: () => relocate('archive'), '#': () => relocate('trash'), u: markUnread, s: toggleFlag }[event.key.toLowerCase()]
  if (action !== undefined) {
    event.preventDefault()
    void action()
  }
}
// A click elsewhere closes the menu.
function outside(event: MouseEvent): void {
  const target = event.target as Node | null
  if (menuOpen.value && target !== null && !menu.value?.contains(target) && !menuButton.value?.contains(target)) {
    menuOpen.value = false
  }
}
onMounted(() => {
  window.addEventListener('keydown', shortcut)
  document.addEventListener('mousedown', outside)
})
onBeforeUnmount(() => {
  unmounted = true
  window.removeEventListener('keydown', shortcut)
  document.removeEventListener('mousedown', outside)
})
</script>

<template>
  <article class="pane" aria-label="Conversation">
    <p v-if="missing" class="missing">This conversation no longer exists. <RouterLink :to="backTo">Back to the inbox</RouterLink></p>
    <div v-else-if="thread" class="inner">
      <div class="actions" role="group" aria-label="Conversation actions">
        <button type="button" class="tool" :disabled="busy" aria-keyshortcuts="E" title="Archive (E)" @click="relocate('archive')"><AppIcon name="archive" label="Archive" /></button>
        <button type="button" class="tool" :disabled="busy" aria-keyshortcuts="#" title="Move to Trash (#)" @click="relocate('trash')"><AppIcon name="trash" label="Move to Trash" /></button>
        <button type="button" class="tool" :disabled="busy" aria-keyshortcuts="U" title="Mark unread (U)" @click="markUnread"><AppIcon name="unread" label="Mark unread" /></button>
        <button type="button" class="tool" :class="{ on: flagged }" :disabled="busy" :aria-pressed="flagged" aria-keyshortcuts="S" :title="flagged ? 'Remove flag (S)' : 'Flag (S)'" @click="toggleFlag">
          <AppIcon name="flag" label="Flag" />
        </button>
        <div class="move">
          <button ref="menuButton" type="button" class="tool wide" :disabled="busy" aria-haspopup="menu" :aria-expanded="menuOpen" @click="menuOpen ? closeMenu() : openMenu()">
            <AppIcon name="folder" /> Move to
          </button>
          <div v-if="menuOpen" ref="menu" class="menu" role="menu" aria-label="Move to folder" @keydown="menuKeydown">
            <button v-for="folder in moveTargets" :key="folder.id" type="button" role="menuitem" tabindex="-1" class="item" @click="moveTo(folder.id)">{{ folder.displayName }}</button>
          </div>
        </div>
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
.actions {
  display: flex;
  align-items: center;
  gap: 4px;
  margin-bottom: 16px;
}
.tool {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  min-width: 36px;
  height: 36px;
  padding: 0 8px;
  border: 0;
  border-radius: var(--radius-control);
  background: transparent;
  color: var(--ink-2);
  font: inherit;
  cursor: pointer;
}
.tool:hover:not(:disabled) {
  background: var(--ground);
  color: var(--ink);
}
.tool:focus-visible,
.item:focus-visible {
  outline: 2px solid var(--focus);
  outline-offset: 1px;
}
.tool:disabled {
  opacity: 0.5;
  cursor: default;
}
.tool.on {
  color: var(--delegate);
}
.move {
  position: relative;
  margin-left: 8px;
}
.menu {
  position: absolute;
  top: 40px;
  left: 0;
  z-index: 10;
  display: flex;
  flex-direction: column;
  min-width: 200px;
  padding: 4px;
  border: 1px solid var(--line);
  border-radius: var(--radius-card);
  background: var(--surface);
  box-shadow: var(--raised);
}
.item {
  padding: 8px 12px;
  border: 0;
  border-radius: var(--radius-control);
  background: transparent;
  color: var(--ink);
  font: inherit;
  text-align: left;
  cursor: pointer;
}
.item:hover,
.item:focus {
  background: var(--selected);
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
