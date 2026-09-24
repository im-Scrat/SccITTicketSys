import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter } from 'react-router-dom'
import { config as zodConfig } from 'zod'
// Self-hosted variable fonts (no CDN — locked-down networks, NFR-CMP-005).
import '@fontsource-variable/inter/wght.css'
import '@fontsource-variable/jetbrains-mono/wght.css'
import App from '@/App'
import { ThemeProvider } from '@/components/ThemeProvider'
import { queryClient } from '@/services/queryClient'
import './index.css'

/*
 * Zod compiles its validators with `new Function` when it can, and probes for
 * that support with a bare `Function('')` in a try/catch. Our production CSP
 * allows no 'unsafe-eval', so the probe is blocked — Zod already handles this
 * and falls back to interpreted validation, but the *attempt* still fires a
 * securitypolicyviolation event on every load.
 *
 * Opting out explicitly keeps the console and any CSP report stream clean, so a
 * real violation stands out instead of being lost among expected noise. The
 * cost is validation Zod cannot JIT, on form payloads small enough that it does
 * not matter.
 */
zodConfig({ jitless: true })

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <ThemeProvider>
        <BrowserRouter>
          <App />
        </BrowserRouter>
      </ThemeProvider>
    </QueryClientProvider>
  </StrictMode>,
)
