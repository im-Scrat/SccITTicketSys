// Static CSP scan of the production build. Runs after `vite build`:
//   node scripts/scan-csp.mjs [distDir]
//
// The production policy (docker/nginx/security-headers.prod.conf) has
// `style-src 'self'` with no 'unsafe-inline', and a script-src with no
// 'unsafe-eval'. Code that breaks it fails only at runtime, only on :8081, and
// only on the screen that runs it: a chart library that injects a <style>
// element renders unstyled, and an eval-based compiler throws. No type check,
// lint rule or unit test notices, because none of them run under that policy.
// This scan reads every built file for the few constructs the policy forbids,
// so a dependency that starts using one fails the gate instead of production.
//
// What stays allowed, and why it is not flagged: React style props and
// `element.style.x = …` go through the CSSOM, which style-src does not govern.
//
// A tripwire, not a proof: it matches source text, so a construct assembled
// at runtime from fragments escapes it. scripts/csp-check.mjs (a real browser
// listening for securitypolicyviolation) is the runtime counterpart.
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'

const DIST = process.argv[2] || 'dist'

const FORBIDDEN = [
  {
    id: 'style-element',
    re: /createElement\(\s*["'`]style["'`]\s*\)/g,
    why: "injects a <style> element; style-src 'self' blocks its rules",
  },
  {
    id: 'style-attribute',
    re: /setAttribute\(\s*["'`]style["'`]/g,
    why: "sets a style attribute; style-src without 'unsafe-inline' blocks it",
  },
  {
    id: 'new-function',
    re: /\bnew Function\s*\(/g,
    why: "compiles code from a string; script-src without 'unsafe-eval' blocks it",
  },
  {
    id: 'eval',
    re: /(?<![\w$.])eval\s*\(/g,
    why: "evaluates a string; script-src without 'unsafe-eval' blocks it",
  },
]

// Reviewed exceptions: { id, file: RegExp, near: RegExp, reason }. `near` must
// match the code around the hit, so an exception covers one known call site,
// never a whole chunk: a second, new construct in the same file still fails.
// An entry needs a reason a reviewer can check.
const ALLOWED = [
  {
    id: 'style-element',
    file: /^assets\/vendor-[\w-]+\.js$/,
    near: /precedence/,
    reason:
      'React DOM 19 resource hoisting. It creates a <style> only when a component ' +
      'renders <style precedence=…>, and no component renders a <style> element ' +
      '(the one `<style>` in src/ is a print-popup document string, not JSX).',
  },
]

function walk(dir) {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    return statSync(path).isDirectory() ? walk(path) : [path]
  })
}

let files
try {
  files = walk(DIST).filter((f) => /\.(js|mjs|html)$/.test(f))
} catch {
  console.error(`scan-csp: ${DIST} not found; run \`vite build\` first.`)
  process.exit(2)
}

const findings = []

for (const file of files) {
  const rel = relative(DIST, file).replaceAll('\\', '/')
  const text = readFileSync(file, 'utf8')

  const rules = rel.endsWith('.html')
    ? [
        // Vite must never emit inline styles into the page itself.
        { id: 'inline-style-tag', re: /<style[\s>]/g, why: 'inline <style> in the page' },
        { id: 'inline-style-attr', re: /\sstyle="/g, why: 'inline style attribute in the page' },
      ]
    : FORBIDDEN

  for (const rule of rules) {
    for (const match of text.matchAll(rule.re)) {
      const at = match.index ?? 0
      const excerpt = text.slice(Math.max(0, at - 60), at + 80).replace(/\s+/g, ' ')
      if (ALLOWED.some((a) => a.id === rule.id && a.file.test(rel) && a.near.test(excerpt))) {
        continue
      }
      findings.push({ file: rel, id: rule.id, why: rule.why, excerpt })
    }
  }
}

if (findings.length > 0) {
  console.error(`scan-csp: ${findings.length} construct(s) the production CSP forbids:\n`)
  for (const f of findings) {
    console.error(`  ${f.file}  [${f.id}] ${f.why}\n    …${f.excerpt}…\n`)
  }
  console.error(
    'Fix the code (or drop the dependency). Do not loosen the policy; a reviewed\n' +
      'exception goes in ALLOWED with a reason.',
  )
  process.exit(1)
}

console.log(
  `scan-csp: ${files.length} built files checked; no construct the production CSP forbids.`,
)
