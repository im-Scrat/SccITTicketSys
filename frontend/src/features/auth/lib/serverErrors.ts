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
