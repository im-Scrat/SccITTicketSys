import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { PasswordInput } from './PasswordInput'

describe('PasswordInput', () => {
  it('masks input by default and toggles visibility', async () => {
    const user = userEvent.setup()
    render(<PasswordInput defaultValue="secret" aria-label="Password" />)

    const input = screen.getByLabelText('Password') as HTMLInputElement
    expect(input.type).toBe('password')

    const toggle = screen.getByRole('button', { name: /show password/i })
    expect(toggle).toHaveAttribute('aria-pressed', 'false')

    await user.click(toggle)

    expect(input.type).toBe('text')
    expect(screen.getByRole('button', { name: /hide password/i })).toHaveAttribute(
      'aria-pressed',
      'true',
    )
  })
})
