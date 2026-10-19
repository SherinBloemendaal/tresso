<script setup lang="ts">
/** Icons from the foundations board: 24 px grid, 2 px stroke, round caps, outline only. */
const paths = {
  inbox: ['M3 13l3-8h12l3 8v6H3z', 'M3 13h5l1 3h6l1-3h5'],
  attachment: ['M21 11l-8.5 8.5a5 5 0 01-7-7L14 4a3.5 3.5 0 015 5l-8.5 8.5a2 2 0 01-3-3L15 7'],
  shield: ['M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z', 'M9 12l2 2 4-4'],
  blocked: ['M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z', 'M9.5 9.5l5 5', 'M14.5 9.5l-5 5'],
  image: ['M4 5h16v14H4z', 'M4 15l4-4 5 5', 'M13 13l2-2 5 5'],
  flag: ['M5 21V4', 'M5 4h11l-2 4 2 4H5'],
  back: ['M15 18l-6-6 6-6'],
  download: ['M12 4v11', 'M7 10l5 5 5-5', 'M5 20h14'],
  outbox: ['M22 2L11 13', 'M22 2l-7 20-4-9-9-4z'],
  reply: ['M9 14L4 9l5-5', 'M4 9h10a6 6 0 016 6v4'],
  replyAll: ['M7 14L2 9l5-5', 'M12 14L7 9l5-5', 'M7 9h7a6 6 0 016 6v4'],
  forward: ['M15 14l5-5-5-5', 'M20 9H10a6 6 0 00-6 6v4'],
} as const

defineProps<{ name: keyof typeof paths; size?: number; label?: string }>()
</script>

<template>
  <svg
    :width="size ?? 18"
    :height="size ?? 18"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
    :role="label ? 'img' : undefined"
    :aria-label="label"
    :aria-hidden="label ? undefined : 'true'"
  >
    <path v-for="d in paths[name]" :key="d" :d="d" />
  </svg>
</template>
