import { useNavigate } from 'react-router-dom'
import { Alert, Button, Modal } from '@/components/ui'
import {
  type CameraScannerEnvironment,
  type ScannerError,
  useCameraScanner,
} from '../hooks/useCameraScanner'
import { resumePath } from '../lib/pendingScan'

interface QrScannerDialogProps {
  open: boolean
  onClose: () => void
  /** Test seam for the camera and decoder; the browser's own in production. */
  environment?: CameraScannerEnvironment
}

const ERRORS: Record<ScannerError, { title: string; body: string; retry: boolean }> = {
  'permission-denied': {
    title: 'Camera access is blocked',
    body: 'Allow camera access for this site in your browser settings, then try again.',
    retry: true,
  },
  'no-camera': {
    title: 'No camera found',
    body: 'This device has no camera the browser can use. Scan the label with a phone instead.',
    retry: true,
  },
  'camera-busy': {
    title: 'The camera could not start',
    body: 'Another app may be using it. Close that app, then try again.',
    retry: true,
  },
  insecure: {
    title: 'The camera needs a secure connection',
    body: 'Open the system over https:// to scan from here, or use your phone’s camera app.',
    retry: false,
  },
  unsupported: {
    title: 'This browser cannot use the camera',
    body: 'Use an up-to-date Chrome, Safari, Edge or Firefox, or your phone’s camera app.',
    retry: false,
  },
  'decoder-unavailable': {
    title: 'The label reader could not load',
    body: 'Check your connection and try again, or scan the label with your phone’s camera app.',
    retry: true,
  },
}

/**
 * The in-app label scanner (owner brief 2026-10-20, SDD DD-117).
 *
 * It reads the **physical label** through the live camera and nothing else:
 * there is no code field, no photo upload and no machine list, because the
 * label is what places the technician at the machine (owner decision D-C, kept
 * as to its purpose). A valid PC label leads to the same in-app panel a phone
 * camera scan reaches, through {@see resumePath} — the server then logs the
 * scan and decides, as always, whether this technician may see that PC.
 *
 * The camera is off whenever the dialog is: `active` follows `open`, and every
 * exit (Cancel, ×, Escape, backdrop, a successful scan) closes it.
 */
export function QrScannerDialog({ open, onClose, environment }: QrScannerDialogProps) {
  const navigate = useNavigate()

  const { videoRef, state, error, notALabel, retry } = useCameraScanner({
    active: open,
    environment,
    onCode: (code) => {
      onClose()
      navigate(resumePath(code))
    },
  })

  const problem = error ? ERRORS[error] : null

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="sm"
      title="Scan a PC label"
      description="Point the camera at the QR label on the machine."
      closeLabel="Close scanner"
      footer={
        <div className="flex flex-wrap justify-end gap-3">
          {problem?.retry && (
            <Button variant="secondary" onClick={() => void retry()}>
              Try again
            </Button>
          )}
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
        </div>
      }
    >
      <div className="flex flex-col gap-4">
        {problem ? (
          <Alert tone="error" title={problem.title}>
            {problem.body}
          </Alert>
        ) : (
          <div className="relative aspect-square w-full overflow-hidden rounded-md bg-black">
            <video
              ref={videoRef}
              className="size-full object-cover"
              muted
              playsInline
              aria-label="Camera preview"
            />
            <span
              aria-hidden="true"
              className="pointer-events-none absolute inset-[18%] rounded-md border-2 border-white/80"
            />
          </div>
        )}

        <p role="status" aria-live="polite" className="text-sm text-muted">
          {statusText(state, notALabel, problem !== null)}
        </p>
      </div>
    </Modal>
  )
}

function statusText(state: string, notALabel: boolean, failed: boolean): string {
  if (failed) return ''
  if (state === 'starting') return 'Starting the camera…'
  if (notALabel)
    return 'That QR code is not a PC label. Point the camera at the label on the machine.'
  if (state === 'scanning')
    return 'Looking for a label. Hold the phone steady about a hand’s width away.'
  return ''
}
