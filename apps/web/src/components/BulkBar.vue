<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import type { MessageAction, SyncedFolder } from '@tresso/core'
import type { Conversation } from '@/mail/db'
import { shortcutAllowed, useMessageActions } from '@/mail/useMessageActions'
import { useComposeStore } from '@/stores/compose'
import { useMailStore } from '@/stores/mail'
import { useSelectionStore } from '@/stores/selection'
import AppIcon from '@/components/AppIcon.vue'
import MenuButton from '@/components/MenuButton.vue'

/** Acts on the conversations selected in the list: the bar above it while any are selected. */
const props = defineProps<{ conversations: Conversation[] }>()

const mail = useMailStore()
const compose = useComposeStore()
const selection = useSelectionStore()
const actions = useMessageActions()
const { busy, exclusive } = actions
const folders = ref<SyncedFolder[]>([])
const moveMenu = ref<InstanceType<typeof MenuButton> | null>(null)

onMounted(async () => {
  folders.value = await mail.folders()
})

const count = computed(() => selection.threadIds.size)
const loadedSelected = computed(() => props.conversations.filter((c) => selection.has(c.threadId)).length)
const allState = computed(() => (loadedSelected.value === 0 ? 'false' : loadedSelected.value === props.conversations.length ? 'true' : 'mixed'))
// Senders of the selected conversations that are loaded; "select all from them" then reaches the whole mailbox.
const senders = computed(() => {
  const addresses = new Set<string>()
  for (const conversation of props.conversations) {
    if (selection.has(conversation.threadId)) {
      for (const sender of conversation.senders) {
        addresses.add(sender.address.toLowerCase())
      }
    }
  }
  return addresses
})
// Moving needs one account: folders belong to an account.
const account = computed(() => {
  const accounts = new Set(props.conversations.filter((c) => selection.has(c.threadId)).map((c) => c.mailAccountId))
  return accounts.size === 1 && loadedSelected.value === count.value ? [...accounts][0] : null
})
const moveTargets = computed(() =>
  folders.value
    .filter((folder) => folder.mailAccountId === account.value && folder.role !== 'drafts' && folder.role !== 'all')
    .sort((a, b) => a.displayName.localeCompare(b.displayName))
    .map((folder) => ({ id: folder.id, label: folder.displayName })),
)

function toggleAll(): void {
  if (allState.value === 'true') {
    selection.clear()
    return
  }
  selection.add(props.conversations.map((c) => c.threadId))
}

function selectSenders(): Promise<void> {
  return exclusive(async () => {
    selection.add(await mail.threadsFrom(senders.value))
  })
}

async function messageIds(): Promise<string[]> {
  return mail.messageIdsOf([...selection.threadIds])
}

function act(action: Extract<MessageAction, 'markRead' | 'markUnread'>): Promise<void> {
  return exclusive(async () => {
    if ((await actions.run(await messageIds(), action)) !== null) {
      selection.clear()
    }
  })
}

function relocate(action: 'archive' | 'trash' | 'move', folderId?: string): Promise<void> {
  return exclusive(async () => {
    if (await actions.relocate(await messageIds(), action, folders.value, folderId)) {
      selection.clear()
    }
  })
}

// K marks read, E archives, # trashes, Esc lets go of the selection.
function shortcut(event: KeyboardEvent): void {
  if (event.key === 'Escape' && moveMenu.value?.open !== true && !(event.target instanceof HTMLElement && event.target.closest('[role="dialog"]'))) {
    selection.clear()
    return
  }
  if (!shortcutAllowed(event, compose.draft !== null && !compose.minimized) || moveMenu.value?.open === true) {
    return
  }
  const action = { k: () => act('markRead'), e: () => relocate('archive'), '#': () => relocate('trash') }[event.key.toLowerCase()]
  if (action !== undefined) {
    event.preventDefault()
    void action()
  }
}
onMounted(() => window.addEventListener('keydown', shortcut))
onBeforeUnmount(() => window.removeEventListener('keydown', shortcut))
</script>

<template>
  <div class="bar" role="group" aria-label="Bulk actions">
    <button type="button" class="tool" role="checkbox" :aria-checked="allState" aria-label="Select all conversations shown" @click="toggleAll">
      <span class="box" :class="allState" aria-hidden="true" />
    </button>
    <span class="count" role="status">{{ count }} selected</span>
    <button type="button" class="tool" :disabled="busy" aria-keyshortcuts="K" title="Mark read (K)" @click="act('markRead')"><AppIcon name="inbox" /> Mark read</button>
    <button type="button" class="tool" :disabled="busy" title="Mark unread" @click="act('markUnread')"><AppIcon name="unread" /> Mark unread</button>
    <button type="button" class="tool" :disabled="busy" aria-keyshortcuts="E" title="Archive (E)" @click="relocate('archive')"><AppIcon name="archive" /> Archive</button>
    <MenuButton
      ref="moveMenu"
      label="Move to"
      menu-label="Move to folder"
      icon="folder"
      :items="moveTargets"
      :disabled="busy"
      :title="account === null ? 'Select conversations of one account to move them' : undefined"
      @choose="(folderId) => relocate('move', folderId)"
    />
    <button type="button" class="tool" :disabled="busy" aria-keyshortcuts="#" title="Move to Trash (#)" @click="relocate('trash')"><AppIcon name="trash" /> Trash</button>
    <button type="button" class="link" :disabled="busy || senders.size === 0" @click="selectSenders">
      Select all from {{ senders.size === 1 ? 'this sender' : `these ${senders.size} senders` }}
    </button>
    <button type="button" class="tool clear" aria-label="Clear the selection" title="Clear the selection (Esc)" @click="selection.clear()"><AppIcon name="close" /></button>
  </div>
</template>

<style scoped>
.bar {
  position: sticky;
  top: 0;
  z-index: 2;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px;
  margin: 8px 12px;
  padding: 6px 8px;
  border: 1px solid var(--line);
  border-radius: var(--radius-card);
  background: var(--surface);
  box-shadow: var(--raised);
}
.count {
  min-width: 84px;
  font-size: 14px;
  font-weight: 600;
}
.tool,
.link {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 34px;
  padding: 0 8px;
  border: 0;
  border-radius: var(--radius-control);
  background: transparent;
  color: var(--ink-2);
  font: inherit;
  font-size: 13px;
  white-space: nowrap;
  cursor: pointer;
}
.tool:hover:not(:disabled) {
  background: var(--ground);
  color: var(--ink);
}
.link {
  color: var(--focus);
  font-weight: 600;
}
.tool:focus-visible,
.link:focus-visible {
  outline: 2px solid var(--focus);
  outline-offset: 1px;
}
.tool:disabled,
.link:disabled {
  opacity: 0.5;
  cursor: default;
}
.clear {
  margin-left: auto;
}
.box {
  width: 16px;
  height: 16px;
  border: 2px solid var(--line-strong);
  border-radius: 4px;
}
.box.true,
.box.mixed {
  border-color: var(--focus);
  background: var(--focus);
}
.box.mixed {
  background: linear-gradient(var(--focus), var(--focus)) center / 8px 2px no-repeat, var(--surface);
}
</style>
