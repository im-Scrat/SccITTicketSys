import { Lock } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Checkbox } from '@/components/ui/Checkbox'
import { Skeleton } from '@/components/ui/Skeleton'
import { Surface } from '@/components/ui/Surface'
import { useUpdateNotificationPreferences } from '../hooks/mutations'
import { useNotificationPreferences } from '../hooks/queries'
import type { NotificationPreference } from '../types'

/**
 * Cells the user cannot switch off, mirroring the server's forced channels.
 *
 * `AccountLocked` declares `forcedChannels() === [Email]` and its type is
 * `system`, so the security notice is mailed regardless of preference — *"a
 * security notice a user silenced six months ago is a security notice that does
 * not exist."*
 *
 * **This is a mirror, and mirrors drift.** The API does not currently publish
 * which cells are forced, so the rule is duplicated here to keep the UI honest;
 * the alternative — a checkbox that accepts a click the server then ignores —
 * is worse, because it lies about what it did. If another notification declares
 * a forced channel, this constant has to be updated with it, and the better fix
 * is for the preferences endpoint to say so in `meta`. Recorded as a WP-2.7b
 * limitation rather than fixed here, which would be a backend change.
 */
const FORCED_CELLS: Record<string, string> = {
  'email:system':
    'Security notices such as an account lockout are always emailed, so a message about your account cannot be silenced in advance.',
}

const cellKey = (channel: string, type: string) => `${channel}:${type}`

/**
 * The notification preference matrix (SRS FR-NOT-002).
 *
 * ── The grid is generated, never hard-coded ────────────────────────────────
 *
 * Rows and columns both come from the API's `meta.types` and `meta.channels`.
 * A type added server-side — D5's announcements, WP-2.4b's procurement — grows
 * this grid with no frontend change, and no list here can fall behind an enum
 * it cannot see. WP-2.7e's `digest` channel took it from 18 cells to 27
 * without touching this file, which is the property working as intended.
 *
 * ── An absent row means enabled, and the server already resolved that ──────
 *
 * `notification_preferences` is opt-out, so a missing row is *enabled*, not
 * unknown. The endpoint fills the gaps before answering, which is why this
 * component never re-implements the rule — it renders what it is given, and
 * falls back to `meta.default_enabled` only for a cell the response did not
 * mention at all.
 *
 * The default is also stated in the copy, once, above the grid: a user who has
 * never opened this screen otherwise cannot tell whether the ticks are their
 * choice or the system's.
 *
 * ── One save, not twenty-seven ─────────────────────────────────────────────
 *
 * Changes accumulate locally and go in a single `PUT`. The endpoint accepts an
 * array precisely so a flaky connection cannot half-write the form, and only
 * the cells that actually changed are sent — so a save says what the user did,
 * not what the screen happened to be showing.
 */
export function PreferenceMatrix() {
  const preferences = useNotificationPreferences()
  const save = useUpdateNotificationPreferences()

  /** Cells the user has changed since the last save, keyed `channel:type`. */
  const [draft, setDraft] = useState<Record<string, boolean>>({})
  const [saved, setSaved] = useState(false)

  const channels = preferences.data?.meta.channels ?? []
  const types = preferences.data?.meta.types ?? []
  const defaultEnabled = preferences.data?.meta.default_enabled ?? true

  const stored = useMemo(() => {
    const map: Record<string, boolean> = {}
    for (const row of preferences.data?.data ?? []) {
      map[cellKey(row.channel, row.notification_type)] = row.is_enabled
    }
    return map
  }, [preferences.data])

  const isEnabled = (channel: string, type: string): boolean => {
    const key = cellKey(channel, type)
    if (key in FORCED_CELLS) return true
    if (key in draft) return draft[key]
    return stored[key] ?? defaultEnabled
  }

  const toggle = (channel: string, type: string, next: boolean) => {
    const key = cellKey(channel, type)
    setSaved(false)
    setDraft((current) => {
      const updated = { ...current, [key]: next }
      // A cell toggled back to its stored value is no longer a change, and
      // sending it would make the save report work that was not done.
      if ((stored[key] ?? defaultEnabled) === next) delete updated[key]
      return updated
    })
  }

  const changed: NotificationPreference[] = Object.entries(draft).map(([key, is_enabled]) => {
    const [channel, notification_type] = key.split(':')
    return { channel, notification_type, is_enabled }
  })

  const submit = async () => {
    if (changed.length === 0) return
    try {
      await save.mutateAsync(changed)
      setDraft({})
      setSaved(true)
    } catch {
      // The Alert below reads the mutation's own error state.
    }
  }

  if (preferences.isPending) {
    return (
      <Surface className="p-5">
        <h2 className="text-sm font-semibold text-ink-strong">Notification preferences</h2>
        <div className="mt-4 space-y-2" role="status" aria-label="Loading notification preferences">
          {Array.from({ length: 5 }).map((_, index) => (
            <Skeleton key={index} height={28} />
          ))}
        </div>
      </Surface>
    )
  }

  if (preferences.isError) {
    return (
      <Surface className="p-5">
        <h2 className="text-sm font-semibold text-ink-strong">Notification preferences</h2>
        <Alert tone="error" className="mt-4" title="Preferences could not be loaded">
          <p>Your current settings are unchanged. Reload the page to try again.</p>
        </Alert>
      </Surface>
    )
  }

  return (
    <Surface className="p-5">
      <h2 className="text-sm font-semibold text-ink-strong">Notification preferences</h2>
      <p className="mt-1 text-xs text-muted">
        {defaultEnabled
          ? 'Everything is on until you turn it off. Choose how each kind of notification reaches you.'
          : 'Choose how each kind of notification reaches you.'}
      </p>

      {saved && (
        <Alert tone="success" className="mt-4">
          Your notification preferences have been saved.
        </Alert>
      )}
      {save.isError && (
        <Alert tone="error" className="mt-4" title="Preferences were not saved">
          <p>Nothing was changed. Check your connection and try again.</p>
        </Alert>
      )}

      {/*
        A data table, in the horizontal-scroll container every wide table in
        this console uses. Types are rows and channels are columns because that
        is the question being asked — "how should this kind of thing reach
        me?" — and WCAG's reflow requirement excepts data tables from the
        no-horizontal-scrolling rule for exactly this shape. A second stacked
        copy for narrow screens was the alternative and was rejected: it would
        duplicate every control in the DOM, and two copies of a form are two
        chances for them to disagree.
      */}
      <div className="mt-4 w-full overflow-x-auto">
        <table className="w-full min-w-[22rem] border-collapse text-sm">
          <caption className="sr-only">
            Notification preferences by type and delivery channel. Each checkbox controls one kind
            of notification on one channel.
          </caption>
          <thead className="border-b border-border">
            <tr>
              <th scope="col" className="px-2 py-2.5 text-left text-xs font-semibold text-muted">
                Notification type
              </th>
              {channels.map((channel) => (
                <th
                  key={channel.value}
                  scope="col"
                  className="px-2 py-2.5 text-center text-xs font-semibold text-muted"
                >
                  {channel.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {types.map((type) => (
              <tr key={type.value}>
                <th
                  scope="row"
                  className="px-2 py-2.5 text-left text-sm font-medium text-ink whitespace-nowrap"
                >
                  {type.label}
                </th>

                {channels.map((channel) => {
                  const key = cellKey(channel.value, type.value)
                  const forcedReason = FORCED_CELLS[key]

                  return (
                    <td key={channel.value} className="px-2 py-2.5 text-center">
                      <span className="inline-flex items-center justify-center gap-1.5">
                        <Checkbox
                          // The accessible name is the *pair*. A bare checkbox
                          // in a grid tells a screen reader nothing about which
                          // cell it is in.
                          label={
                            forcedReason
                              ? `${channel.label} notifications for ${type.label} — always on`
                              : `${channel.label} notifications for ${type.label}`
                          }
                          labelHidden
                          checked={isEnabled(channel.value, type.value)}
                          disabled={forcedReason !== undefined}
                          title={forcedReason}
                          onChange={(event) =>
                            toggle(channel.value, type.value, event.target.checked)
                          }
                        />
                        {forcedReason && (
                          <Lock size={13} className="text-muted" aria-hidden="true" />
                        )}
                      </span>
                    </td>
                  )
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/*
        The locked cell is explained in text below the grid, not only in a
        tooltip: a disabled control with no stated reason reads as a bug, and a
        `title` is invisible to touch and to most screen readers.
      */}
      {Object.values(FORCED_CELLS).map((reason) => (
        <p key={reason} className="mt-3 flex gap-2 text-xs text-muted">
          <Lock size={13} className="mt-0.5 shrink-0" aria-hidden="true" />
          <span>{reason}</span>
        </p>
      ))}

      <div className="mt-5 flex items-center gap-3">
        <Button
          type="button"
          variant="primary"
          size="sm"
          disabled={changed.length === 0}
          loading={save.isPending}
          onClick={submit}
        >
          Save preferences
        </Button>
        {changed.length > 0 && (
          <p className="text-xs text-muted">
            {changed.length} unsaved {changed.length === 1 ? 'change' : 'changes'}
          </p>
        )}
      </div>
    </Surface>
  )
}
