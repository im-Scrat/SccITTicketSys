import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { fileURLToPath, URL } from 'node:url'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  build: {
    // Long-term caching: split the always-loaded, rarely-changing core
    // libraries into a single stable `vendor` chunk. App code changes on
    // every deploy; this chunk does not, so browsers keep it cached across
    // releases. Deliberately narrow — lucide-react (per-icon chunks) and zod
    // (its own chunk) are left to Vite's default splitting, preserving the
    // existing route-level code-splitting from App.tsx.
    rollupOptions: {
      output: {
        manualChunks(id) {
          if (!id.includes('node_modules')) return undefined
          if (
            /[\\/]node_modules[\\/](react|react-dom|scheduler|react-router|react-router-dom|@tanstack[\\/]react-query|@tanstack[\\/]query-core|zustand|axios)[\\/]/.test(
              id,
            )
          ) {
            return 'vendor'
          }
          // The real-time client (services/echo.ts) gets its own stable chunk
          // rather than joining `vendor`: it is dynamically imported on first
          // subscription, so the public site and most screens never fetch it,
          // yet once fetched it stays cached across deploys like `vendor`.
          if (/[\\/]node_modules[\\/](laravel-echo|pusher-js|tweetnacl)[\\/]/.test(id)) {
            return 'realtime'
          }
          return undefined
        },
      },
    },
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    // Served behind the single-origin Nginx proxy on :8080, so the HMR
    // client in the browser must dial back through that public port.
    hmr: {
      clientPort: 8080,
    },
    // Docker Desktop on Windows doesn't forward native FS events into the
    // Linux container — poll so file changes still trigger HMR.
    watch: {
      usePolling: true,
      interval: 300,
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: './src/test/setup.ts',
    css: true,
    /*
     * Vitest owns `src/`; Playwright owns `e2e/`.
     *
     * Vitest's default `include` is `**\/*.{test,spec}.?(c|m)[jt]s?(x)`, which
     * matches the browser specs in e2e/ by name. Without this they are imported
     * into jsdom, where `@playwright/test` is not a test runner and the fixture
     * manifest's file:// URL cannot resolve — four failures that say nothing
     * about the application. They are run by `sh scripts/e2e.sh`.
     */
    include: ['src/**/*.{test,spec}.{ts,tsx}'],
  },
})
