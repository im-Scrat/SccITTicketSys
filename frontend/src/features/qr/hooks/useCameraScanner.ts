import { useCallback, useEffect, useRef, useState } from 'react'
import { codeFromScannedPayload } from '../lib/scannedPayload'
import { createQrDecoder, DecoderUnavailableError, type QrDecoder } from '../lib/qrDecoder'

/**
 * The live camera behind the in-app label scanner (owner brief 2026-10-20,
 * SDD DD-117).
 *
 * **The camera is released on every way out.** Close, Cancel, Escape, a
 * successful scan, an error, navigating away and unmounting all reach
 * {@see stop}, which stops every track — the phone's camera light goes out. A
 * `getUserMedia` that resolves *after* the dialog closed (the permission prompt
 * is still up when the technician gives up) is caught by the session counter
 * and its tracks are stopped on arrival, rather than leaking an open camera.
 *
 * Errors are named, so the dialog can say what to do about each one. Only a
 * value {@see codeFromScannedPayload} accepts is ever passed on; any other QR
 * code is reported as "not a PC label" while scanning continues.
 */

export type ScannerError =
  | 'insecure'
  | 'unsupported'
  | 'permission-denied'
  | 'no-camera'
  | 'camera-busy'
  | 'decoder-unavailable'

export type ScannerState = 'idle' | 'starting' | 'scanning' | 'error'

/** Between decode attempts: quick enough to feel live, light on older phones. */
export const SCAN_INTERVAL_MS = 200

export interface CameraScannerEnvironment {
  isSecureContext?: () => boolean
  mediaDevices?: () => Pick<MediaDevices, 'getUserMedia'> | undefined
  createDecoder?: () => Promise<QrDecoder>
}

interface Options {
  /** Scanning runs while true; turning it false releases the camera. */
  active: boolean
  /** Called once, with the code of a valid PC label; the camera is already off. */
  onCode: (code: string) => void
  environment?: CameraScannerEnvironment
}

export function useCameraScanner({ active, onCode, environment }: Options) {
  const videoRef = useRef<HTMLVideoElement | null>(null)
  const streamRef = useRef<MediaStream | null>(null)
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const sessionRef = useRef(0)
  const onCodeRef = useRef(onCode)
  const envRef = useRef(environment)

  const [state, setState] = useState<ScannerState>('idle')
  const [error, setError] = useState<ScannerError | null>(null)
  const [notALabel, setNotALabel] = useState(false)

  useEffect(() => {
    onCodeRef.current = onCode
    envRef.current = environment
  })

  const stop = useCallback(() => {
    sessionRef.current += 1
    if (timerRef.current !== null) {
      clearTimeout(timerRef.current)
      timerRef.current = null
    }
    streamRef.current?.getTracks().forEach((track) => track.stop())
    streamRef.current = null
    if (videoRef.current) {
      videoRef.current.srcObject = null
    }
  }, [])

  const fail = useCallback(
    (reason: ScannerError) => {
      stop()
      setError(reason)
      setState('error')
    },
    [stop],
  )

  const start = useCallback(async () => {
    stop()
    const session = sessionRef.current
    const env = envRef.current ?? {}
    const current = () => session === sessionRef.current

    setError(null)
    setNotALabel(false)
    setState('starting')

    const secure = env.isSecureContext ? env.isSecureContext() : window.isSecureContext
    if (!secure) return fail('insecure')

    const devices = env.mediaDevices ? env.mediaDevices() : navigator.mediaDevices
    if (!devices?.getUserMedia) return fail('unsupported')

    let stream: MediaStream
    try {
      stream = await devices.getUserMedia({
        video: { facingMode: { ideal: 'environment' } },
        audio: false,
      })
    } catch (reason) {
      if (current()) fail(cameraError(reason))
      return
    }

    if (!current()) {
      // Closed while the permission prompt was up: release what just arrived.
      stream.getTracks().forEach((track) => track.stop())
      return
    }
    streamRef.current = stream

    let decoder: QrDecoder
    try {
      decoder = await (env.createDecoder ?? createQrDecoder)()
    } catch {
      if (current()) fail('decoder-unavailable')
      return
    }
    if (!current()) return

    const video = videoRef.current
    if (video) {
      video.srcObject = stream
      // Autoplay of a muted inline preview is allowed; a refusal is not fatal,
      // the frames still arrive once the element plays.
      try {
        await video.play()
      } catch {
        // Nothing to do.
      }
    }
    if (!current()) return

    setState('scanning')

    const tick = async () => {
      if (!current()) return
      const frame = videoRef.current

      if (frame && frame.readyState >= 2) {
        let text: string | null = null
        try {
          text = await decoder.decode(frame)
        } catch (reason) {
          if (!current()) return
          if (reason instanceof DecoderUnavailableError) return fail('decoder-unavailable')
          // A single unreadable frame is not an error worth showing.
        }
        if (!current()) return

        if (text !== null) {
          const code = codeFromScannedPayload(text)
          if (code !== null) {
            stop()
            setState('idle')
            onCodeRef.current(code)
            return
          }
          setNotALabel(true)
        }
      }

      timerRef.current = setTimeout(tick, SCAN_INTERVAL_MS)
    }

    void tick()
  }, [fail, stop])

  useEffect(() => {
    if (!active) {
      stop()
      setState('idle')
      return
    }

    void start()

    return stop
  }, [active, start, stop])

  return { videoRef, state, error, notALabel, retry: start, stop }
}

function cameraError(reason: unknown): ScannerError {
  const name = reason instanceof DOMException || reason instanceof Error ? reason.name : ''

  switch (name) {
    case 'NotAllowedError':
    case 'SecurityError':
      return 'permission-denied'
    case 'NotFoundError':
    case 'OverconstrainedError':
      return 'no-camera'
    default:
      // NotReadableError, AbortError: another app holds the camera, or the
      // hardware refused to start.
      return 'camera-busy'
  }
}
