import axios from 'axios'
import type { FieldValues, Path, UseFormSetError } from 'react-hook-form'
import type { AccountStatusError } from '../types'

/** Human-readable message from an API error, with a sensible fallback. */
export function getErrorMessage(
  error: unknown,
  fallback = 'Something went wrong. Please try again.',
): string {
  if (axios.isAxiosError(error)) {
    const data = error.response?.data as { message?: string } | undefined
    return data?.message ?? fallback
  }
  return fallback
}

/** Map a Laravel 422 validation response onto react-hook-form fields. */
export function applyServerErrors<T extends FieldValues>(
  error: unknown,
  setError: UseFormSetError<T>,
): boolean {
  if (axios.isAxiosError(error) && error.response?.status === 422) {
    const errors = (error.response.data as { errors?: Record<string, string[]> }).errors ?? {}
    for (const [field, messages] of Object.entries(errors)) {
      if (messages[0]) {
        setError(field as Path<T>, { type: 'server', message: messages[0] })
      }
    }
    return true
  }
  return false
}

export interface MappedServerErrors<T extends FieldValues> {
  /** Form fields that received a message, in the server's order. */
  placed: Path<T>[]
  /** Messages with nowhere to go — show these in the form-level alert. */
  unplaced: string[]
}

/**
 * Map a Laravel 422 onto the fields a form actually shows.
 *
 * {@link applyServerErrors} hands every key to `setError` and reports success,
 * which is right when the payload's keys and the form's fields are the same
 * names. When they are not — the report form sends one "item" choice as either
 * `pc_unit` or `asset`, and reveals some fields only in some states — a message
 * for a key the form does not render is stored on a path nobody sees, and the
 * person is left looking at a button that simply stopped spinning.
 *
 * So each server key is looked up in `map` (server key → form field), and is
 * placed only when `isRendered` says that field is on screen right now. Every
 * other message comes back in `unplaced` for the form's own alert. Returns
 * `null` when the error is not a validation response at all.
 */
export function applyMappedServerErrors<T extends FieldValues>(
  error: unknown,
  setError: UseFormSetError<T>,
  map: Partial<Record<string, Path<T>>>,
  isRendered: (field: Path<T>) => boolean,
): MappedServerErrors<T> | null {
  if (!axios.isAxiosError(error) || error.response?.status !== 422) return null

  const data = error.response.data as { message?: string; errors?: Record<string, string[]> }
  const placed: Path<T>[] = []
  const unplaced: string[] = []

  for (const [key, messages] of Object.entries(data.errors ?? {})) {
    const message = messages[0]
    if (!message) continue

    const field = map[key]
    if (field && isRendered(field)) {
      if (!placed.includes(field)) {
        setError(field, { type: 'server', message })
        placed.push(field)
      }
    } else if (!unplaced.includes(message)) {
      unplaced.push(message)
    }
  }

  // A 422 with no field errors at all still has something to say.
  if (placed.length === 0 && unplaced.length === 0 && data.message) unplaced.push(data.message)

  return { placed, unplaced }
}

/** Extract the account-status body from a 403 login response, if present. */
export function getAccountStatusError(error: unknown): AccountStatusError | null {
  if (axios.isAxiosError(error) && error.response?.status === 403) {
    const data = error.response.data as Partial<AccountStatusError> | undefined
    if (data?.code) {
      return data as AccountStatusError
    }
  }
  return null
}
