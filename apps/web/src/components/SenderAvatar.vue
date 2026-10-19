<script setup lang="ts">
import { computed } from 'vue'
import type { Address } from '@tresso/core'

const props = defineProps<{ sender: Address; size?: number }>()

// Neutral-leaning tints; color carries no meaning here, it only tells people apart.
const tints = [
  ['#DDE3FB', '#1A31A8'],
  ['#E3F2EE', '#1E5A4E'],
  ['#FCEEE2', '#7A3704'],
  ['#EDE7F8', '#4B2F86'],
  ['#F3F4F6', '#4A505C'],
] as const

const initials = computed(() => {
  const source = props.sender.name?.trim() || props.sender.address
  const words = source.replace(/@.*/, '').split(/[\s._-]+/).filter(Boolean)
  const letters = words.length > 1 ? (words[0]![0] ?? '') + (words[words.length - 1]![0] ?? '') : source.slice(0, 2)

  return letters.toUpperCase()
})

const tint = computed(() => {
  let hash = 0
  for (const char of props.sender.address.toLowerCase()) {
    hash = (hash * 31 + char.charCodeAt(0)) >>> 0
  }

  return tints[hash % tints.length]!
})
</script>

<template>
  <span
    class="avatar"
    aria-hidden="true"
    :style="{ width: `${size ?? 40}px`, height: `${size ?? 40}px`, background: tint[0], color: tint[1], fontSize: `${Math.round((size ?? 40) / 3)}px` }"
    >{{ initials }}</span
  >
</template>

<style scoped>
.avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  border-radius: 999px;
  font-weight: 700;
}
</style>
