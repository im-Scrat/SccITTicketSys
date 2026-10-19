// CSP verification harness — run inside the `node` container:
//   node scripts/csp-check.mjs [baseOrigin] [nginxHost] [email] [password]
//
// Loads the real SPA in Chromium and reports every Content-Security-Policy
// violation and console error the app actually produces. Config inspection
// cannot tell you whether a policy breaks React, Vite HMR, fonts, or the
// blob:-URL attachment previews — only a browser can.
//
// Uses the host-resolver trick so the browser's origin stays localhost:<port>
// (Sanctum cookies are first-party) while the request reaches the nginx service.
import { chromium } from 'playwright-core'

const ORIGIN = process.argv[2] || 'http://localhost:8080'
const MAP_TO = process.argv[3] || 'nginx:80'
const EMAIL = process.argv[4] || 'arttesting@sccit.local'
const PASSWORD = process.argv[5] || 'Artscc'
const PORT = new URL(ORIGIN).port

const browser = await chromium.launch({
  executablePath: '/usr/bin/chromium',
  args: ['--no-sandbox', `--host-resolver-rules=MAP localhost:${PORT} ${MAP_TO}`],
})

const page = await browser.newPage()

const violations = []
const consoleErrors = []
const failedRequests = []

// The spec event: fires for every blocked resource, with the directive that did it.
await page.addInitScript(() => {
  window.__cspViolations = []
  document.addEventListener('securitypolicyviolation', (e) => {
    window.__cspViolations.push({
      directive: e.effectiveDirective || e.violatedDirective,
      blockedURI: e.blockedURI,
      source: e.sourceFile ? `${e.sourceFile}:${e.lineNumber}` : null,
    })
  })
})

page.on('console', (m) => {
  if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 300))
})
page.on('requestfailed', (r) => {
  failedRequests.push(`${r.method()} ${r.url().slice(0, 120)} — ${r.failure()?.errorText}`)
})

async function drain(label) {
  const v = await page.evaluate(() => {
    const out = window.__cspViolations || []
    window.__cspViolations = []
    return out
  })
  v.forEach((x) => violations.push({ ...x, at: label }))
}

async function settle(ms = 2500) {
  await page
    .waitForSelector('svg.animate-spin', { state: 'detached', timeout: 25000 })
    .catch(() => {})
  await page.waitForTimeout(ms)
}

// ---- 1. Public landing (unauthenticated) ----
await page.goto(`${ORIGIN}/`, { waitUntil: 'domcontentloaded' })
await settle()
await drain('landing')

// Did the anti-FOUC inline script actually run? If the CSP hash is wrong in
// production, this attribute is missing and the page flashes the wrong theme.
const themeApplied = await page.evaluate(() => document.documentElement.getAttribute('data-theme'))

// ---- 2. Sign in ----
await page.goto(`${ORIGIN}/sign-in`, { waitUntil: 'domcontentloaded' })
await settle(1500)
await drain('sign-in')

let loggedIn = false
try {
  await page.fill('input[type="email"]', EMAIL)
  await page.fill('input[type="password"]', PASSWORD)
  await Promise.all([
    page.waitForURL((u) => !u.pathname.includes('sign-in'), { timeout: 25000 }),
    page.click('button[type="submit"]'),
  ])
  loggedIn = true
} catch (e) {
  consoleErrors.push(`LOGIN FAILED: ${String(e).slice(0, 200)}`)
}
await settle()
await drain('dashboard')

// ---- 3. Authenticated surfaces that exercise fonts, styles, XHR ----
for (const path of ['/tickets/feed', '/tickets/mine', '/assets']) {
  await page.goto(`${ORIGIN}${path}`, { waitUntil: 'domcontentloaded' }).catch(() => {})
  await settle(1800)
  await drain(path)
}

// ---- 4. The blob: path — the one img-src rule most likely to be wrong ----
// Fetches through the app's own axios origin and renders it exactly as
// PrivateImage does, so a missing `blob:` in img-src shows up as a violation.
const blobProbe = await page.evaluate(async () => {
  try {
    const blob = new Blob(
      [
        Uint8Array.from(
          atob(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
          ),
          (c) => c.charCodeAt(0),
        ),
      ],
      { type: 'image/png' },
    )
    const url = URL.createObjectURL(blob)
    const ok = await new Promise((resolve) => {
      const img = new Image()
      img.onload = () => resolve(true)
      img.onerror = () => resolve(false)
      img.src = url
      setTimeout(() => resolve(false), 4000)
    })
    URL.revokeObjectURL(url)
    return ok
  } catch (e) {
    return `error: ${String(e)}`
  }
})
await drain('blob-image')

console.log(
  JSON.stringify(
    {
      origin: ORIGIN,
      themeInlineScriptRan: themeApplied,
      loggedIn,
      blobImageRendered: blobProbe,
      cspViolations: violations,
      consoleErrors: consoleErrors.slice(0, 20),
      failedRequests: failedRequests.slice(0, 20),
    },
    null,
    2,
  ),
)

await browser.close()
