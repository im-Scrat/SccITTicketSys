import { useMutation, useQueryClient } from '@tanstack/react-query'
import { notificationKeys } from '@/features/notifications/hooks/queries'
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
import type { AuthUser, LoginPayload, RegisterPayload } from '../types'
import { AUTH_USER_KEY } from './useAuth'

export function useLogin() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: LoginPayload) => login(payload),
    onSuccess: (user: AuthUser) => {
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
 * **Notifications are removed here for a specific reason.** They are addressed
 * to one person by name and are the most personal thing this client caches — on
 * a shared machine, a list that survived a sign-out would show the next user
 * what the last one was told. TanStack keeps cached data after the query's
 * observers unmount, so signing out and signing in as someone else would render
 * the previous inbox from cache for as long as it took the refetch to land.
 * Removing the key makes that window not exist.
 */
export function useLogout() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => logout(),
    onSettled: () => {
      queryClient.setQueryData(AUTH_USER_KEY, null)
      queryClient.removeQueries({ queryKey: ['registrations'] })
      queryClient.removeQueries({ queryKey: notificationKeys.all })
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

export function useChangePassword() {
  return useMutation({
    mutationFn: (payload: ChangePasswordPayload) => changePassword(payload),
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
