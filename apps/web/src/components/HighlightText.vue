<script setup lang="ts">
import type { HighlightSegment } from '@tresso/core'

defineProps<{ segments: HighlightSegment[] }>()
</script>

<template>
  <template v-for="(segment, index) in segments" :key="index">
    <mark v-if="segment.match" class="match">{{ segment.text }}</mark>
    <template v-else>{{ segment.text }}</template>
  </template>
</template>

<style scoped>
.match {
  padding: 0 2px;
  border-radius: 3px;
  background: var(--match);
  color: var(--ink);
}
</style>
