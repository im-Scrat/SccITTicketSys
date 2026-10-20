import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { CameraScannerEnvironment } from '../hooks/useCameraScanner'
import type { QrDecoder } from '../lib/qrDecoder'
import { DecoderUnavailableError } from '../lib/qrDecoder'
import { QrScannerDialog } from './QrScannerDialog'

const LABEL = 'https://sccit-itsm.onrender.com/qr/PC-ABCDEFGHJK'

function fakeStream() {
  const track = { stop: vi.fn() }
  return { track, stream: { getTracks: () => [track] } as unknown as MediaStream }
}

function decoderReturning(...texts: Array<string | null>): QrDecoder {
  let call = 0
  return {
    kind: 'native',
    decode: vi.fn(async () => texts[Math.min(call++, texts.length - 1)] ?? null),
  }
}

function environment(overrides: Partial<CameraScannerEnvironment> = {}) {
  const { track, stream } = fakeStream()
  const getUserMedia = vi.fn(async () => stream)
  const env: CameraScannerEnvironment = {
    isSecureContext: () => true,
    mediaDevices: () => ({ getUserMedia }),
    createDecoder: async () => decoderReturning(null),
    ...overrides,
  }
  return { env, track, getUserMedia }
}

function Where() {
  const location = useLocation()
  return <p data-testid="where">{location.pathname}</p>
}

function Harness({ env }: { env: CameraScannerEnvironment }) {
  const [open, setOpen] = useState(true)
  return (
    <>
      <button type="button" onClick={() => setOpen(true)}>
        Reopen
      </button>
      {open && <QrScannerDialog open={open} onClose={() => setOpen(false)} environment={env} />}
      <Where />
    </>
  )
}

function renderDialog(env: CameraScannerEnvironment) {
  return render(
    <MemoryRouter initialEntries={['/app']}>
      <Routes>
        <Route path="*" element={<Harness env={env} />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  // jsdom has no media pipeline: report a playing video with a frame ready.
  vi.spyOn(HTMLMediaElement.prototype, 'play').mockResolvedValue(undefined)
  vi.spyOn(HTMLMediaElement.prototype, 'readyState', 'get').mockReturnValue(4)
})

afterEach(() => vi.restoreAllMocks())

describe('QrScannerDialog — scanning a label', () => {
  it('opens the scanned PC’s panel through the in-app route and turns the camera off', async () => {
    const { env, track, getUserMedia } = environment({
      createDecoder: async () => decoderReturning(null, LABEL),
    })
    renderDialog(env)

    expect(screen.getByRole('dialog', { name: 'Scan a PC label' })).toBeInTheDocument()
    expect(getUserMedia).toHaveBeenCalledWith({
      video: { facingMode: { ideal: 'environment' } },
      audio: false,
    })

    await waitFor(() =>
      expect(screen.getByTestId('where')).toHaveTextContent('/app/qr/PC-ABCDEFGHJK'),
    )
    expect(track.stop).toHaveBeenCalled()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('says so when a QR code is not a PC label, and keeps scanning', async () => {
    const { env, track } = environment({
      createDecoder: async () => decoderReturning('https://example.org/menu', null),
    })
    renderDialog(env)

    expect(await screen.findByText(/That QR code is not a PC label/)).toBeInTheDocument()
    expect(screen.getByTestId('where')).toHaveTextContent('/app')
    expect(track.stop).not.toHaveBeenCalled()
  })

  it('offers no way to type a code or upload a picture', async () => {
    const { env } = environment()
    renderDialog(env)
    await screen.findByText(/Looking for a label/)

    const dialog = screen.getByRole('dialog')
    expect(dialog.querySelector('input, textarea, select')).toBeNull()
  })
})

describe('QrScannerDialog — releasing the camera', () => {
  it('stops the camera on Cancel', async () => {
    const { env, track } = environment()
    renderDialog(env)
    await screen.findByText(/Looking for a label/)

    await userEvent.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(track.stop).toHaveBeenCalled()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('stops the camera on Escape', async () => {
    const { env, track } = environment()
    renderDialog(env)
    await screen.findByText(/Looking for a label/)

    await userEvent.keyboard('{Escape}')

    expect(track.stop).toHaveBeenCalled()
  })

  it('stops a camera that arrives after the dialog was closed', async () => {
    const { track, stream } = fakeStream()
    let grant: (value: MediaStream) => void = () => undefined
    const { env } = environment({
      mediaDevices: () => ({
        getUserMedia: () => new Promise<MediaStream>((resolve) => (grant = resolve)),
      }),
    })
    const view = renderDialog(env)

    view.unmount()
    grant(stream)

    await waitFor(() => expect(track.stop).toHaveBeenCalled())
  })
})

describe('QrScannerDialog — when the camera cannot be used', () => {
  it.each([
    ['NotAllowedError', 'Camera access is blocked'],
    ['NotFoundError', 'No camera found'],
    ['NotReadableError', 'The camera could not start'],
  ])('explains %s and offers to try again', async (name, title) => {
    const getUserMedia = vi
      .fn()
      .mockRejectedValueOnce(new DOMException('denied', name))
      .mockResolvedValue(fakeStream().stream)
    const { env } = environment({ mediaDevices: () => ({ getUserMedia }) })
    renderDialog(env)

    expect(await screen.findByRole('alert')).toHaveTextContent(title)

    await userEvent.click(screen.getByRole('button', { name: 'Try again' }))

    expect(getUserMedia).toHaveBeenCalledTimes(2)
    await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument())
  })

  it('refuses to start without a secure connection, with no retry', async () => {
    const { env, getUserMedia } = environment({ isSecureContext: () => false })
    renderDialog(env)

    expect(await screen.findByRole('alert')).toHaveTextContent('needs a secure connection')
    expect(getUserMedia).not.toHaveBeenCalled()
    expect(screen.queryByRole('button', { name: 'Try again' })).not.toBeInTheDocument()
  })

  it('explains a browser with no camera API', async () => {
    const { env } = environment({ mediaDevices: () => undefined })
    renderDialog(env)

    expect(await screen.findByRole('alert')).toHaveTextContent('cannot use the camera')
  })

  it('explains a label reader that could not load, and releases the camera', async () => {
    const { env, track } = environment({
      createDecoder: async () => {
        throw new DecoderUnavailableError()
      },
    })
    renderDialog(env)

    expect(await screen.findByRole('alert')).toHaveTextContent('The label reader could not load')
    expect(track.stop).toHaveBeenCalled()
  })

  it('stops scanning when the decoder gives out mid-scan', async () => {
    const { env, track } = environment({
      createDecoder: async () => ({
        kind: 'native',
        decode: vi.fn(async () => {
          throw new DecoderUnavailableError()
        }),
      }),
    })
    renderDialog(env)

    expect(await screen.findByRole('alert')).toHaveTextContent('The label reader could not load')
    expect(track.stop).toHaveBeenCalled()
  })
})
