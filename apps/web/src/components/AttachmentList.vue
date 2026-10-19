<script setup lang="ts">
import { fileSize, SCAN_TEXT, type MessageAttachment } from '@tresso/core'
import AppIcon from '@/components/AppIcon.vue'

defineProps<{ attachments: MessageAttachment[] }>()

</script>

<template>
  <ul v-if="attachments.length > 0" class="attachments" aria-label="Attachments">
    <li v-for="attachment in attachments" :key="attachment.id" class="attachment" :class="{ blocked: attachment.url === null }">
      <span class="icon">
        <AppIcon :name="attachment.url === null ? 'blocked' : attachment.scanStatus === 'clean' ? 'shield' : 'attachment'" />
      </span>
      <span class="text">
        <a v-if="attachment.url" :href="attachment.url" class="name" target="_blank" rel="noopener noreferrer">{{ attachment.filename }}</a>
        <span v-else class="name">{{ attachment.filename }}</span>
        <span class="detail">{{ fileSize(attachment.size) }}, {{ SCAN_TEXT[attachment.scanStatus] }}</span>
      </span>
      <AppIcon v-if="attachment.url" name="download" class="download" />
    </li>
  </ul>
</template>

<style scoped>
.attachments {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin: 0;
  padding: 0;
  list-style: none;
}
.attachment {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 300px;
  max-width: 100%;
  padding: 10px 12px;
  border: 1px solid var(--line);
  border-radius: var(--radius-card);
}
.icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: var(--radius-control);
  background: var(--ground);
  color: var(--ink-2);
  flex-shrink: 0;
}
.blocked .icon {
  background: var(--danger-soft);
  color: var(--danger);
}
.text {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
}
.name {
  font-weight: 600;
  color: var(--ink);
  text-decoration: none;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
a.name:hover {
  text-decoration: underline;
}
.detail {
  font-size: 12px;
  color: var(--ink-2);
}
.blocked .detail {
  color: var(--danger);
}
.download {
  color: var(--ink-3);
}
</style>
