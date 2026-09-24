import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

/**
 * The fixture manifest written by `scripts/e2e.sh`, which runs
 * `php artisan sccit:e2e-fixtures --json` inside the app container and
 * redirects its output here.
 *
 * The suite reads accounts and identifiers from this file rather than hard-
 * coding them, so there is exactly one definition of the fixture set — in the
 * Artisan command — and the browser tests cannot drift from what was seeded.
 */
export type FixtureManifest = {
  password: string
  users: Record<Role, { email: string; uuid: string }>
  qr: { code: string; status: string; scan_path: string }
  pc_unit: { uuid: string; unit_code: string; pc_name: string }
  room: { uuid: string; name: string }
  maintenance: { uuid: string; status: string }
}

export type Role = 'administrator' | 'technician' | 'teacher'

export const ROLES: Role[] = ['administrator', 'technician', 'teacher']

const MANIFEST_PATH = fileURLToPath(new URL('../.artifacts/fixtures.json', import.meta.url))

let cached: FixtureManifest | null = null

/**
 * Fail loudly and usefully when the manifest is missing. Someone running
 * `npx playwright test` directly — which is the natural thing to try — would
 * otherwise get a bare ENOENT with no indication that a seeding step exists.
 */
export function manifest(): FixtureManifest {
  if (cached !== null) return cached

  try {
    cached = JSON.parse(readFileSync(MANIFEST_PATH, 'utf8')) as FixtureManifest
  } catch {
    throw new Error(
      `No fixture manifest at ${MANIFEST_PATH}.\n` +
        'The browser suite runs against seeded, deterministic data. Start it with:\n' +
        '    sh scripts/e2e.sh\n' +
        'which seeds the fixtures and then invokes Playwright.',
    )
  }

  return cached
}

export function account(role: Role): { email: string; password: string } {
  const m = manifest()

  return { email: m.users[role].email, password: m.password }
}
