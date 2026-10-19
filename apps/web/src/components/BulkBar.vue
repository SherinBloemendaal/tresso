<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import type { MessageAction } from '@tresso/core'
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
const { busy } = storeToRefs(selection)
const actions = useMessageActions(busy)
const { exclusive } = actions
const moveMenu = ref<InstanceType<typeof MenuButton> | null>(null)

// What the selection holds, from the local copy rather than the loaded part of the list: threads that left the
// mailbox meanwhile (sync from another device) drop out of it.
const accounts = ref<string[]>([])
const senders = ref(new Set<string>())
let described = 0
watch(
  [() => selection.threadIds, () => mail.conversations],
  async () => {
    // Only the newest answer counts, and it only removes what it found gone among the threads it was asked about:
    // an older, slower answer must not undo a selection the user just made.
    const request = ++described
    const asked = [...selection.threadIds]
    const info = await mail.describeThreads(asked)
    if (request !== described) {
      return
    }
    const present = new Set(info.present)
    selection.remove(asked.filter((id) => !present.has(id)))
    accounts.value = info.accounts
    senders.value = new Set(info.senders)
  },
  { immediate: true },
)

const count = computed(() => selection.threadIds.size)
const shownSelected = computed(() => props.conversations.filter((c) => selection.has(c.threadId)).length)
const allState = computed(() => (shownSelected.value === 0 ? 'false' : shownSelected.value === props.conversations.length ? 'true' : 'mixed'))
// Moving needs one account: folders belong to an account.
const account = computed(() => (accounts.value.length === 1 ? accounts.value[0] : null))
const moveTargets = computed(() =>
  mail.allFolders
    .filter((folder) => folder.mailAccountId === account.value && folder.role !== 'drafts' && folder.role !== 'all')
    .sort((a, b) => a.displayName.localeCompare(b.displayName))
    .map((folder) => ({ id: folder.id, label: folder.displayName })),
)

// Acts on what is shown only: conversations selected further down (by age or sender) stay selected.
function toggleAll(): void {
  const shown = props.conversations.map((c) => c.threadId)
  if (allState.value === 'true') {
    selection.remove(shown)
    return
  }
  selection.add(shown)
}

function selectSenders(): Promise<void> {
  return exclusive(async () => {
    selection.add(await mail.threadsFrom(senders.value))
  })
}

// The threads an action takes leave the selection when it is done; ones the user added meanwhile stay.
function act(action: Extract<MessageAction, 'markRead' | 'markUnread'>): Promise<void> {
  return exclusive(async () => {
    const threadIds = [...selection.threadIds]
    if ((await actions.run(await mail.messageIdsOf(threadIds), action)) !== null) {
      selection.remove(threadIds)
    }
  })
}

function relocate(action: 'archive' | 'trash' | 'move', folderId?: string): Promise<void> {
  return exclusive(async () => {
    const threadIds = [...selection.threadIds]
    if (await actions.relocate(await mail.messageIdsOf(threadIds), action, mail.allFolders, folderId)) {
      selection.remove(threadIds)
    }
  })
}

// K marks read, E archives, # trashes, Esc lets go of the selection; not while typing, writing or in a menu (an
// Escape that closed a menu or left a field was handled there already).
function shortcut(event: KeyboardEvent): void {
  if (!shortcutAllowed(event, compose.draft !== null && !compose.minimized) || moveMenu.value?.open === true) {
    return
  }
  if (event.key === 'Escape') {
    selection.clear()
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
      :title="account === null ? 'Conversations of several accounts are selected; folders belong to one account' : undefined"
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
