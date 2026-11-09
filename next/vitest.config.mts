import { fileURLToPath, URL } from 'node:url'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@shared': fileURLToPath(new URL('../shared', import.meta.url)),
      '@': fileURLToPath(new URL('./src', import.meta.url)),
      // Next.js handles `import 'server-only'` itself; in tests it's an empty module.
      'server-only': fileURLToPath(new URL('./src/test/empty.ts', import.meta.url)),
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    // An API URL that isn't a real one, so a test that forgets to mock fetch can't reach it.
    env: { API_BASE_URL: 'http://api.test' },
  },
})
