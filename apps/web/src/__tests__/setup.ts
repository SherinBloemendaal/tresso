import { afterEach } from 'vitest'
import { enableAutoUnmount } from '@vue/test-utils'

// Every component a test mounts goes when the test ends. One left mounted keeps its window listeners, watchers and
// requests running in the tests after it, against stores those tests already stopped.
enableAutoUnmount(afterEach)
