import { describe, expect, it } from 'vitest'
import { createUserSchema, editRegistrationSchema, editUserSchema } from './schemas'

const validProfile = {
  first_name: 'Nova',
  last_name: 'Rhodes',
  email: 'nova@example.com',
  middle_name: '',
  employee_number: '',
  contact_number: '',
}

describe('createUserSchema', () => {
  it('accepts a valid payload', () => {
    const result = createUserSchema.safeParse({
      ...validProfile,
      role: 'technician',
      status: 'active',
      password: 'Str0ng-P@ssw0rd',
      password_confirmation: 'Str0ng-P@ssw0rd',
      force_password_reset: false,
    })
    expect(result.success).toBe(true)
  })

  it('rejects a weak password', () => {
    const result = createUserSchema.safeParse({
      ...validProfile,
      role: 'technician',
      status: 'active',
      password: 'password',
      password_confirmation: 'password',
      force_password_reset: false,
    })
    expect(result.success).toBe(false)
  })

  it('rejects mismatched password confirmation', () => {
    const result = createUserSchema.safeParse({
      ...validProfile,
      role: 'technician',
      status: 'active',
      password: 'Str0ng-P@ssw0rd',
      password_confirmation: 'Different-1',
      force_password_reset: false,
    })
    expect(result.success).toBe(false)
  })

  it('requires a role', () => {
    const result = createUserSchema.safeParse({
      ...validProfile,
      role: '',
      status: 'active',
      password: 'Str0ng-P@ssw0rd',
      password_confirmation: 'Str0ng-P@ssw0rd',
      force_password_reset: false,
    })
    expect(result.success).toBe(false)
  })
})

describe('editUserSchema', () => {
  it('requires first and last name', () => {
    expect(editUserSchema.safeParse({ ...validProfile, first_name: '' }).success).toBe(false)
    expect(editUserSchema.safeParse(validProfile).success).toBe(true)
  })
})

describe('editRegistrationSchema', () => {
  it('constrains the role to teacher or technician', () => {
    expect(
      editRegistrationSchema.safeParse({ ...validProfile, role: 'administrator' }).success,
    ).toBe(false)
    expect(editRegistrationSchema.safeParse({ ...validProfile, role: 'teacher' }).success).toBe(
      true,
    )
  })
})
