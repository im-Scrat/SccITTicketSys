import { isAxiosError } from 'axios'
import type { ScanRefusalReason, WorkTarget } from '../types'

/**
 * Turning a server refusal into something a technician can act on.
 *
 * The API rations its reasons deliberately (FR-QR-013): a caller who clears the
 * maintenance floor is staff doing the work and gets a specific cause, while
 * anyone else gets one flat answer for every cause. This module renders
 * whichever arrives and **never guesses** — an unrecognised or absent reason
 * falls back to the generic copy rather than inventing a diagnosis, because a
 * confident wrong explanation sends someone to fetch a sticker they did not
 * need.
 */

export interface Refusal {
  title: string
  body: string
  /** What to actually do about it, when there is something. */
  action?: string
}

const REFUSALS: Record<ScanRefusalReason, Refusal> = {
  not_authorized: {
    title: 'This workflow is not available to your account',
    body: 'Scanning equipment labels is part of the maintenance workflow, and your account does not take part in it.',
    action: 'If you think that is wrong, ask an administrator to check your role.',
  },
  label_unknown: {
    title: 'This label is not recognised',
    body: 'Nothing in the system matches the code on this sticker. It may belong to another system, or have been printed before this equipment was registered.',
    action: 'Ask an administrator to issue a label for this machine.',
  },
  label_inactive: {
    title: 'This label is out of date',
    body: 'The sticker on this machine has been replaced or withdrawn, so it no longer identifies equipment that is in service.',
    action: 'Ask an administrator for a replacement label.',
  },
  no_workflow: {
    title: 'This label is not for a computer unit',
    body: 'The code identifies a piece of equipment rather than a workstation, and the on-site job workflow covers workstations only.',
  },
  not_reachable: {
    title: 'You have no assigned work on this machine',
    body: 'Scanning a label identifies equipment; it does not grant access to it. This panel opens for machines you have an open maintenance record or an active ticket assignment on.',
    action: 'If this job is yours, ask an administrator to assign it to you.',
  },
}

const GENERIC: Refusal = {
  title: 'This equipment is not available to you',
  body: 'The system would not open this machine for your account.',
}

/** The refusal a `{ next: "refused" }` scan response describes. */
export function refusalFor(reason: ScanRefusalReason | undefined): Refusal {
  return reason !== undefined && reason in REFUSALS ? REFUSALS[reason] : GENERIC
}

/**
 * The refusal an error response describes, or null when it is not one.
 *
 * A 403 from the panel may or may not carry a `reason`: the label checks do,
 * while the reachability check is a policy denial and arrives as Laravel's plain
 * authorization response. Both are refusals to the person reading the screen,
 * and both are rendered as one.
 */
export function refusalFromError(error: unknown): Refusal | null {
  if (!isAxiosError(error) || error.response?.status !== 403) return null

  const reason = (error.response.data as { reason?: ScanRefusalReason } | undefined)?.reason

  return reason !== undefined ? refusalFor(reason) : REFUSALS.not_reachable
}

/**
 * The candidate jobs a 422 attaches when the machine carries more than one and
 * the server refuses to choose (FR-MNT-009).
 */
export function selectionTargetsFromError(error: unknown): WorkTarget[] | null {
  if (!isAxiosError(error) || error.response?.status !== 422) return null

  const targets = (error.response.data as { targets?: WorkTarget[] } | undefined)?.targets

  return Array.isArray(targets) && targets.length > 0 ? targets : null
}
