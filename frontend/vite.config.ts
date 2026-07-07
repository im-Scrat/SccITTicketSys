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
  },
})
