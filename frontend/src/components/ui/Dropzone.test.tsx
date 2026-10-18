import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { Dropzone } from './Dropzone'

const MB = 1024 * 1024

function Harness({ onReject = () => {} }: { onReject?: (reason: string) => void }) {
  const [file, setFile] = useState<File | null>(null)
  return (
    <Dropzone
      accept={['.xlsx', '.csv']}
      maxBytes={10 * MB}
      file={file}
      onFile={setFile}
      onReject={onReject}
      label="Inventory file"
      hint="Excel .xlsx or CSV, up to 10 MB"
    />
  )
}

const fileOf = (name: string, bytes = 10) => new File(['x'.repeat(bytes)], name)

describe('Dropzone', () => {
  it('takes an accepted file from the picker and shows it', async () => {
    render(<Harness />)
    const input = screen.getByLabelText('Inventory file')

    await userEvent.upload(input, fileOf('Inventory 2026.XLSX', 2048))

    expect(screen.getByText('Inventory 2026.XLSX')).toBeInTheDocument()
    expect(screen.getByText('2 KB')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Remove Inventory 2026.XLSX' })).toBeInTheDocument()
  })

  it('refuses another type with a plain reason', async () => {
    const onReject = vi.fn()
    render(<Harness onReject={onReject} />)

    // userEvent.upload honours `accept` by default; turn that off to reach our check.
    await userEvent
      .setup({ applyAccept: false })
      .upload(screen.getByLabelText('Inventory file'), fileOf('inventory.xls'))

    expect(screen.getByRole('alert')).toHaveTextContent(
      "That file type isn't accepted. Choose a file ending in .xlsx or .csv.",
    )
    expect(onReject).toHaveBeenCalledWith('type', expect.any(File))
  })

  it('refuses a file over the size limit', async () => {
    const onReject = vi.fn()
    render(<Harness onReject={onReject} />)
    const big = fileOf('big.csv')
    Object.defineProperty(big, 'size', { value: 11 * MB })

    await userEvent.upload(screen.getByLabelText('Inventory file'), big)

    expect(screen.getByRole('alert')).toHaveTextContent('That file is larger than 10 MB.')
    expect(onReject).toHaveBeenCalledWith('size', big)
  })

  it('clears the choice and returns focus to the picker', async () => {
    render(<Harness />)
    const input = screen.getByLabelText('Inventory file')
    await userEvent.upload(input, fileOf('rooms.csv'))

    await userEvent.click(screen.getByRole('button', { name: 'Remove rooms.csv' }))

    expect(screen.queryByText('rooms.csv')).not.toBeInTheDocument()
    expect(input).toHaveFocus()
  })
})
