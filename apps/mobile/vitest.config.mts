import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vitest/config'

const alias = { '@': fileURLToPath(new URL('./src', import.meta.url)) }

export default defineConfig({
  test: {
    projects: [
      // Unit tests for the parts that do not need React Native (sessions, storage, sign-in results); native modules are
      // mocked in the tests.
      {
        resolve: { alias },
        test: { name: 'unit', include: ['src/**/*.test.ts'] },
      },
      // Screens and providers, rendered through React Native for Web in a browser-like DOM. Native modules (the router,
      // web views, safe areas) are mocked in the tests.
      {
        resolve: { alias: { ...alias, 'react-native': 'react-native-web' } },
        test: { name: 'components', include: ['src/**/*.test.tsx'], environment: 'jsdom', setupFiles: ['src/test/setup.ts'] },
      },
    ],
  },
})
