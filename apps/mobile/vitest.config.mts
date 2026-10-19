import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vitest/config'

// Unit tests for the parts that do not need React Native (sessions, storage, sign-in results); native modules are
// mocked in the tests.
export default defineConfig({
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  test: {
    include: ['src/**/*.test.ts'],
  },
})
