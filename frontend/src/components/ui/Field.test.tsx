import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { Field } from './Field'
import { Input } from './Input'

describe('Field', () => {
  it('associates the label with the control', () => {
    render(
      <Field label="Email">
        <Input />
      </Field>,
    )
    // getByLabelText resolves the label→control association via the injected id.
    expect(screen.getByLabelText('Email')).toBeInTheDocument()
  })

  it('wires an error to aria-invalid and aria-describedby, and announces it', () => {
    render(
      <Field label="Email" error="Email is required.">
        <Input />
      </Field>,
    )

    const input = screen.getByLabelText('Email')
    expect(input).toHaveAttribute('aria-invalid', 'true')

    const error = screen.getByRole('alert')
    expect(error).toHaveTextContent('Email is required.')
    expect(input.getAttribute('aria-describedby')).toBe(error.id)
  })

  it('shows a hint when there is no error', () => {
    render(
      <Field label="Employee number" hint="Optional">
        <Input />
      </Field>,
    )
    expect(screen.getByText('Optional')).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })
})
