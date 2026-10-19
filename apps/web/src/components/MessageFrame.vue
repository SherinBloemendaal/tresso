<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { mailFrameDocument } from '@tresso/core'
import { config } from '@/config'

const props = defineProps<{ html: string; allowRemoteContent: boolean }>()

const frame = ref<HTMLIFrameElement | null>(null)
const height = ref(120)
let observer: ResizeObserver | null = null

const document = computed(() =>
  mailFrameDocument(props.html, { apiOrigin: new URL(config.apiUrl).origin, allowRemoteContent: props.allowRemoteContent }),
)

// The frame shares the app's origin only so its height can be measured: without allow-scripts nothing in it runs.
function measure(): void {
  const root = frame.value?.contentDocument?.documentElement
  if (root) {
    height.value = Math.max(root.scrollHeight, 40)
  }
}

function loaded(): void {
  observer?.disconnect()
  const root = frame.value?.contentDocument?.documentElement
  if (!root) {
    return
  }
  measure()
  observer = new ResizeObserver(measure)
  observer.observe(root)
}

onBeforeUnmount(() => observer?.disconnect())
</script>

<template>
  <iframe
    ref="frame"
    class="frame"
    title="Message content"
    sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
    referrerpolicy="no-referrer"
    :srcdoc="document"
    :style="{ height: `${height}px` }"
    @load="loaded"
  />
</template>

<style scoped>
.frame {
  display: block;
  width: 100%;
  border: 0;
  border-radius: var(--radius-card);
  background: #fff;
}
</style>
