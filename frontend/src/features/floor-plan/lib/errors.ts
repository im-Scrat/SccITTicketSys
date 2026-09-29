import { isAxiosError } from 'axios'

/**
 * Why a placement was refused, in words an administrator can act on.
 *
 * Keyed on the server's machine-readable `code` (`FloorPlanRuleViolation`) and
 * HTTP status, never on its prose, so wording here can change without the
 * server and vice versa. Validation messages (422 with `errors`) are the
 * server's own, because they name the layout's actual bounds.
 */
export function placementErrorMessage(error: unknown): string {
  if (!isAxiosError(error) || !error.response) {
    return 'The move could not be saved. Check your connection and try again.'
  }

  const { status, data } = error.response as {
    status: number
    data?: { code?: string; message?: string; errors?: Record<string, string[]> }
  }

  switch (data?.code) {
    case 'position_occupied':
      return 'Another unit already stands at that position. Choose a free spot.'
    case 'layout_not_active':
      return 'This layout is no longer the active one. The plan has been reloaded.'
    case 'pc_unit_not_in_room':
      return 'That unit is no longer in this room. The plan has been reloaded.'
    case 'position_stale':
      return 'Someone else already moved this unit. It has been updated to its current position.'
  }

  if (status === 422 && data?.errors) {
    const first = Object.values(data.errors).flat()[0]
    if (first) return first
  }

  if (status === 404)
    return 'That unit or layout no longer exists here. The plan has been reloaded.'
  if (status === 403 || status === 401)
    return 'You do not have permission to change this floor plan.'

  return 'The move could not be saved. Please try again.'
}

/** Field-level messages from a 422, keyed by field (`x`, `y`). */
export function placementFieldErrors(error: unknown): Record<string, string> {
  if (!isAxiosError(error) || error.response?.status !== 422) return {}
  const errors = (error.response.data as { errors?: Record<string, string[]> } | undefined)?.errors
  if (!errors) return {}

  return Object.fromEntries(
    Object.entries(errors)
      .filter(([, messages]) => messages.length > 0)
      .map(([field, messages]) => [field, messages[0]]),
  )
}
