/**
 * Date/label formatting for the User Management surfaces.
 *
 * The implementations moved to `@/lib/datetime` in Phase 2.4 so every module —
 * Users, Locations and the dashboards — formats timestamps identically. These
 * re-exports keep this slice's existing imports working unchanged.
 */
export { formatDate, formatDateTime, formatRelative, titleCase } from '@/lib/datetime'
