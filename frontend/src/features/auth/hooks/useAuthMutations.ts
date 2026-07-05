import { useMutation, useQueryClient } from '@tanstack/react-query'
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

export function useLogout() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => logout(),
    onSettled: () => {
      queryClient.setQueryData(AUTH_USER_KEY, null)
      queryClient.removeQueries({ queryKey: ['registrations'] })
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
