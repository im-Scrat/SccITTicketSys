import { z } from 'zod'

/** Count distinct character classes present in a password (mirrors backend). */
function characterClasses(value: string): number {
  let count = 0
  if (/[a-z]/.test(value)) count++
  if (/[A-Z]/.test(value)) count++
  if (/\d/.test(value)) count++
  if (/[^A-Za-z0-9]/.test(value)) count++
  return count
}

const password = z
  .string()
  .min(10, 'Use at least 10 characters.')
  .refine(
    (value) => characterClasses(value) >= 3,
    'Include at least 3 of: lowercase, uppercase, digit, and symbol.',
  )

const profileShape = {
  first_name: z.string().min(1, 'First name is required.').max(100),
  middle_name: z.string().max(100).optional().or(z.literal('')),
  last_name: z.string().min(1, 'Last name is required.').max(100),
  email: z.string().min(1, 'Email is required.').email('Enter a valid email address.'),
  employee_number: z.string().max(50).optional().or(z.literal('')),
  contact_number: z.string().max(30).optional().or(z.literal('')),
}

export const createUserSchema = z
  .object({
    ...profileShape,
    role: z.string().min(1, 'Choose a role.'),
    status: z.enum(['active', 'inactive', 'suspended', 'pending']),
    password,
    password_confirmation: z.string().min(1, 'Please confirm the password.'),
    force_password_reset: z.boolean(),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: 'Passwords do not match.',
    path: ['password_confirmation'],
  })
export type CreateUserForm = z.infer<typeof createUserSchema>

export const editUserSchema = z.object(profileShape)
export type EditUserForm = z.infer<typeof editUserSchema>

export const editRegistrationSchema = z.object({
  ...profileShape,
  role: z.enum(['teacher', 'technician']),
})
export type EditRegistrationForm = z.infer<typeof editRegistrationSchema>
