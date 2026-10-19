/**
 * One plain sentence per priority, shown under each choice on the report form
 * (SDD DD-75).
 *
 * Keyed by slug — the contract — never by name: "Critical" is displayed
 * "Urgent" (DD-76) and an administrator may rename any of them, but `critical`
 * stays `critical`. A priority an administrator adds later simply has no guide
 * rather than a guessed one.
 *
 * The wording describes the effect on work, not the reporter's feelings about
 * it, so two people with the same fault land on the same answer; and it never
 * mentions response times, which a requester is not shown.
 */
export const PRIORITY_GUIDES: Readonly<Record<string, string>> = {
  low: 'Minor — work can carry on.',
  medium: 'Gets in the way, but there’s a workaround.',
  high: 'Stops work for a room or a group of people.',
  critical: 'A safety risk, or many people can’t work right now.',
}
