import { describe, expect, it } from 'vitest'
import { loginSchema, registerSchema } from './schemas'

describe('loginSchema', () => {
  it('accepts a valid credential pair', () => {
    expect(loginSchema.safeParse({ email: 'a@b.com', password: 'x', remember: true }).success).toBe(
      true,
    )
  })

  it('rejects an invalid email', () => {
    expect(loginSchema.safeParse({ email: 'not-an-email', password: 'x' }).success).toBe(false)
  })

  it('rejects an empty password', () => {
    expect(loginSchema.safeParse({ email: 'a@b.com', password: '' }).success).toBe(false)
  })
})

describe('registerSchema', () => {
  const base = {
    first_name: 'Alex',
    last_name: 'Rivera',
    email: 'alex@example.com',
    role: 'teacher' as const,
    password: 'Str0ng-Passw0rd!',
    password_confirmation: 'Str0ng-Passw0rd!',
  }

  it('accepts a valid teacher registration', () => {
    expect(registerSchema.safeParse(base).success).toBe(true)
  })

  it('rejects a password shorter than 10 characters', () => {
    expect(
      registerSchema.safeParse({ ...base, password: 'Ab1!', password_confirmation: 'Ab1!' })
        .success,
    ).toBe(false)
  })

  it('rejects a password with fewer than three character classes', () => {
    const weak = 'alllowercaseonly'
    expect(
      registerSchema.safeParse({ ...base, password: weak, password_confirmation: weak }).success,
    ).toBe(false)
  })

  it('rejects a mismatched confirmation with a field-scoped error', () => {
    const result = registerSchema.safeParse({ ...base, password_confirmation: 'Different-123!' })
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(
        result.error.issues.some((issue) => issue.path.includes('password_confirmation')),
      ).toBe(true)
    }
  })

  it('rejects a role that is not teacher or technician', () => {
    expect(registerSchema.safeParse({ ...base, role: 'administrator' }).success).toBe(false)
  })
})
