<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { signatureFrameDocument } from '@tresso/core'

const props = defineProps<{ html: string; background: 'light' | 'dark' }>()

const frame = ref<HTMLIFrameElement | null>(null)
const height = ref(80)
let observer: ResizeObserver | null = null

const document = computed(() => signatureFrameDocument(props.html, { background: props.background }))

// Same setup as the message frame: same origin only to measure the height, and without allow-scripts nothing runs.
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
    :title="`Signature preview on a ${background} background`"
    sandbox="allow-same-origin"
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
}
</style>
