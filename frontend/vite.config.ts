import { defineConfig } from 'vitest/config'
import { loadEnv, type ProxyOptions, type ServerOptions } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { fileURLToPath, URL } from 'node:url'

// The retired development stack ran Vite in a container behind its Nginx on
// :8080. Kept as the default because CI's opt-in browser job still uses it.
const devStackServer: ServerOptions = {
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
}

/*
 * `npm run dev:live` — Vite on this PC, everything else from production.
 *
 * With the development stack retired, this is how a frontend edit is seen
 * without a deploy: Vite serves src/ with hot reload and forwards every server
 * path to the production stack on :8081, as production's own Nginx does. The
 * data is production's — the preview is not a sandbox. :8081 itself changes
 * only on deploy.
 */
const PRODUCTION = 'http://localhost:8081'

// Sanctum keeps a session only for requests whose Referer/Origin is one of its
// stateful domains, and production lists localhost:8081 — not this port.
// Presenting each forwarded request as coming from :8081 leaves production's
// configuration exactly as it is.
//
// The same holds for the Reverb socket, whose allowed origin is `localhost`:
// a WebSocket upgrade fires `proxyReqWs`, not `proxyReq`, so it needs its own
// rewrite — without it a browser on the Tailscale address is refused.
//
// Production sets its cookies with `Domain=localhost`. A browser on any other
// host (the Tailscale URL) silently drops those, and sign-in fails with a 419.
// Stripping the attribute makes each cookie host-only for whichever address
// the browser is on. Host-only cookies ignore the port, so a sign-in on
// localhost:5173 still serves localhost:8081 as before.
const toProduction: ProxyOptions = {
  target: PRODUCTION,
  changeOrigin: true,
  cookieDomainRewrite: { localhost: '' },
  configure(proxy) {
    proxy.on('proxyReq', (request) => {
      request.setHeader('origin', PRODUCTION)
      request.setHeader('referer', `${PRODUCTION}/`)
    })
    proxy.on('proxyReqWs', (request) => {
      request.setHeader('origin', PRODUCTION)
    })
  },
}

function liveServer(mode: string): ServerOptions {
  /*
   * Extra hostnames the preview answers to, e.g. a Tailscale Serve name, from
   * SCCIT_LIVE_HOSTS in frontend/.env.live.local (gitignored, per machine).
   * Vite refuses any other Host header — its guard against DNS rebinding — so
   * the list is exact names, never a wildcard suffix.
   */
  const envDir = fileURLToPath(new URL('.', import.meta.url))
  const extraHosts = (loadEnv(mode, envDir, 'SCCIT_').SCCIT_LIVE_HOSTS ?? '')
    .split(',')
    .map((host) => host.trim())
    .filter(Boolean)

  return {
    // Loopback only: the preview acts as whoever signs in, against live data,
    // so it is reachable from other machines only through Tailscale Serve.
    // 127.0.0.1, not `localhost`: Node resolves `localhost` to ::1 first, and
    // Serve's upstream is IPv4 — that mismatch was the 502. Browsers opening
    // http://localhost:5173 fall back to 127.0.0.1 on their own.
    host: '127.0.0.1',
    port: 5173,
    strictPort: true,
    allowedHosts: extraHosts,
    proxy: {
      '/api': toProduction,
      '/sanctum': toProduction,
      '/broadcasting': toProduction,
      '/storage': toProduction,
      '/up': toProduction,
      // echo.ts dials window.location + /reverb, so the socket arrives here.
      '/reverb': { ...toProduction, ws: true },
    },
  }
}

// https://vite.dev/config/
export default defineConfig(({ mode }) => ({
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
  // `server` applies to `vite` (dev) only; `vite build` ignores it.
  server: mode === 'live' ? liveServer(mode) : devStackServer,
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
}))
