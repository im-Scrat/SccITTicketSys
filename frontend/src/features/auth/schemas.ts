import { z } from 'zod'

/** Count distinct character classes present in a password. */
function characterClasses(value: string): number {
  let count = 0
  if (/[a-z]/.test(value)) count++
  if (/[A-Z]/.test(value)) count++
  if (/\d/.test(value)) count++
  if (/[^A-Za-z0-9]/.test(value)) count++
  return count
}

/**
 * Client mirror of the backend PasswordPolicy (FR-AUTH-003): length + ≥3 classes.
 * The common-password check stays server-side; its error surfaces via 422.
 */
const password = z
  .string()
  .min(10, 'Use at least 10 characters.')
  .refine(
    (value) => characterClasses(value) >= 3,
    'Include at least 3 of: lowercase, uppercase, digit, and symbol.',
  )

export const loginSchema = z.object({
  email: z.string().min(1, 'Email is required.').email('Enter a valid email address.'),
  password: z.string().min(1, 'Password is required.'),
  remember: z.boolean().optional(),
})
export type LoginForm = z.infer<typeof loginSchema>

export const registerSchema = z
  .object({
    first_name: z.string().min(1, 'First name is required.').max(100),
    middle_name: z.string().max(100).optional().or(z.literal('')),
    last_name: z.string().min(1, 'Last name is required.').max(100),
    email: z.string().min(1, 'Email is required.').email('Enter a valid email address.'),
    employee_number: z.string().max(50).optional().or(z.literal('')),
    contact_number: z.string().max(30).optional().or(z.literal('')),
    role: z.enum(['teacher', 'technician']),
    password,
    password_confirmation: z.string().min(1, 'Please confirm your password.'),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: 'Passwords do not match.',
    path: ['password_confirmation'],
  })
export type RegisterForm = z.infer<typeof registerSchema>

export const forgotPasswordSchema = z.object({
  email: z.string().min(1, 'Email is required.').email('Enter a valid email address.'),
})
export type ForgotPasswordForm = z.infer<typeof forgotPasswordSchema>

export const resetPasswordSchema = z
  .object({
    password,
    password_confirmation: z.string().min(1, 'Please confirm your password.'),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: 'Passwords do not match.',
    path: ['password_confirmation'],
  })
export type ResetPasswordForm = z.infer<typeof resetPasswordSchema>

export const changePasswordSchema = z
  .object({
    current_password: z.string().min(1, 'Enter your current password.'),
    password,
    password_confirmation: z.string().min(1, 'Please confirm your password.'),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: 'Passwords do not match.',
    path: ['password_confirmation'],
  })
  .refine((data) => data.password !== data.current_password, {
    message: 'Your new password must be different from your current password.',
    path: ['password'],
  })
export type ChangePasswordForm = z.infer<typeof changePasswordSchema>
