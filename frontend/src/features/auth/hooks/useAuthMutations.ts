import { type QueryClient, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  changePassword,
  type ChangePasswordPayload,
  forgotPassword,
  login,
  logout,
  register,
  resetPassword,
  type ResetPasswordPayload,
  updateProfile,
  type UpdateProfilePayload,
} from '../api/authApi'
import { clearReportDrafts, clearReportDraftsExcept } from '@/features/tickets/hooks/useReportDraft'
import type { AuthUser, LoginPayload, RegisterPayload } from '../types'
import { AUTH_USER_KEY } from './useAuth'

/**
 * Remove every cached query except the auth key.
 *
 * Everything this client caches was fetched *as* one principal: their tickets,
 * dashboard, AI analyses, assistant conversations, notifications. TanStack keeps
 * that data after its observers unmount, so on a shared machine the next person
 * to sign in would see the previous one's screens rendered from cache until each
 * refetch landed. Dropping the whole cache — not a list of keys someone has to
 * remember to extend — makes that window not exist for any feature, current or
 * future (Phase 2.9; WP-2.7b fixed this for notifications alone).
 */
function dropPrincipalCache(queryClient: QueryClient): void {
  queryClient.removeQueries({
    predicate: (query) => query.queryKey[0] !== AUTH_USER_KEY[0],
  })
}

export function useLogin() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: LoginPayload) => login(payload),
    onSuccess: (user: AuthUser) => {
      // A sign-in can follow an *expired* session without a logout in between,
      // possibly as someone else — so the previous principal's cache goes here
      // too, before the new user's first screen reads from it. So does anyone
      // else's unsent report in this tab (SDD DD-80); the person signing in
      // keeps their own, which is what it was saved for.
      dropPrincipalCache(queryClient)
      clearReportDraftsExcept(user.id)
      queryClient.setQueryData(AUTH_USER_KEY, user)
    },
  })
}

/**
 * End the session, then drop what the previous principal could read.
 *
 * `onSettled` rather than `onSuccess`: a logout whose request failed still ends
 * the session as far as this browser is concerned, and leaving the cache
 * populated because the network blinked is the wrong way round.
 *
 * **The whole cache goes, not a list of keys.** WP-2.7b removed notifications
 * and registrations here and nothing else, which left tickets, the dashboard,
 * AI analyses and every other per-user query on screen for the next person on a
 * shared machine until each refetch landed. {@link dropPrincipalCache} removes
 * all of it, so a feature added later cannot reopen the gap by being forgotten.
 *
 * The same goes for an unsent problem report kept in this tab's session storage
 * (SDD DD-80): signing out is the person saying they are done, and the next
 * person at the keyboard must not be offered their half-written report.
 */
export function useLogout() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => logout(),
    onSettled: () => {
      queryClient.setQueryData(AUTH_USER_KEY, null)
      dropPrincipalCache(queryClient)
      clearReportDrafts()
    },
  })
}

export function useRegister() {
  return useMutation({
    mutationFn: (payload: RegisterPayload) => register(payload),
  })
}

export function useForgotPassword() {
  return useMutation({
    mutationFn: (email: string) => forgotPassword(email),
  })
}

export function useResetPassword() {
  return useMutation({
    mutationFn: (payload: ResetPasswordPayload) => resetPassword(payload),
  })
}

/**
 * A successful change clears `force_password_reset` server-side
 * (ChangeUserPassword). The cached principal still carries the old flag, and
 * ProtectedRoute reads the cache — so the principal is re-fetched before the
 * mutation settles, or a forced user would be bounced straight back to the
 * change screen they just completed.
 */
export function useChangePassword() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: ChangePasswordPayload) => changePassword(payload),
    onSuccess: () => queryClient.refetchQueries({ queryKey: AUTH_USER_KEY }),
  })
}

export function useUpdateProfile() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: UpdateProfilePayload) => updateProfile(payload),
    onSuccess: (user: AuthUser) => {
      queryClient.setQueryData(AUTH_USER_KEY, user)
    },
  })
}
