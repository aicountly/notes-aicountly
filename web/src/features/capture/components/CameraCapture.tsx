/**
 * The live camera, and one still frame from it.
 *
 * Everything that makes camera code go wrong is handled here so the scanner
 * does not have to: the stream is stopped on unmount (a camera light left on
 * after a dialog closes is a privacy bug, not a cosmetic one), permission
 * denial is a state with an explanation rather than an exception, and a device
 * with no camera at all renders the caller's fallback instead of a shutter
 * button that throws.
 *
 * The frame is taken at the sensor's own resolution — `videoWidth`, not the
 * CSS size of the element — because a document photographed at the size of a
 * dialog is unreadable.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import type { ReactNode } from 'react'

import { Icon } from '../../../shared/ui/Icon'
import { Button } from '../../../shared/ui/primitives'
import '../capture.css'

export interface CapturedImage {
  blob: Blob
  width: number
  height: number
}

export type CameraState = 'starting' | 'live' | 'denied' | 'unavailable' | 'error'

/** False on an insecure origin, an old browser, or a device with no camera API. */
export function isCameraSupported(): boolean {
  return (
    typeof navigator !== 'undefined' &&
    typeof navigator.mediaDevices?.getUserMedia === 'function'
  )
}

/** JPEG at a quality that keeps text legible without a 6 MB page. */
const CAPTURE_QUALITY = 0.92

export interface CameraCaptureProps {
  onCapture: (image: CapturedImage) => void
  /** Offered whenever the camera cannot be used — typically a file picker. */
  fallback?: ReactNode
  facingMode?: 'environment' | 'user'
  shutterLabel?: string
}

export function CameraCapture({
  onCapture,
  fallback,
  facingMode = 'environment',
  shutterLabel = 'Take a photo',
}: CameraCaptureProps) {
  const videoRef = useRef<HTMLVideoElement>(null)
  const streamRef = useRef<MediaStream | null>(null)
  const [state, setState] = useState<CameraState>('starting')
  const [message, setMessage] = useState<string | null>(null)
  const [attempt, setAttempt] = useState(0)

  useEffect(() => {
    if (!isCameraSupported()) {
      setState('unavailable')
      setMessage(
        'This browser cannot reach a camera. That is usually because the page is not on a secure (https) connection, or the device has no camera.',
      )
      return undefined
    }

    let cancelled = false
    setState('starting')
    setMessage(null)

    navigator.mediaDevices
      .getUserMedia({ video: { facingMode }, audio: false })
      .then((stream) => {
        if (cancelled) {
          // The dialog closed while the permission prompt was open. Releasing
          // the tracks here is the only thing that turns the camera light off.
          stream.getTracks().forEach((track) => track.stop())
          return
        }
        streamRef.current = stream
        if (videoRef.current) {
          videoRef.current.srcObject = stream
        }
        setState('live')
      })
      .catch((error: unknown) => {
        if (cancelled) return
        const name = error instanceof Error ? error.name : ''

        if (name === 'NotAllowedError' || name === 'SecurityError') {
          setState('denied')
          setMessage(
            'This site does not have permission to use the camera. Allow camera access for this site in your browser’s address bar, then try again.',
          )
          return
        }
        if (name === 'NotFoundError' || name === 'OverconstrainedError') {
          setState('unavailable')
          setMessage('No camera was found on this device.')
          return
        }
        setState('error')
        setMessage(
          name === 'NotReadableError'
            ? 'The camera is in use by another app. Close it and try again.'
            : 'The camera could not be started.',
        )
      })

    return () => {
      cancelled = true
      streamRef.current?.getTracks().forEach((track) => track.stop())
      streamRef.current = null
    }
  }, [attempt, facingMode])

  const capture = useCallback(() => {
    const video = videoRef.current
    if (!video || video.videoWidth === 0) return

    const canvas = document.createElement('canvas')
    canvas.width = video.videoWidth
    canvas.height = video.videoHeight

    const context = canvas.getContext('2d')
    if (!context) return
    context.drawImage(video, 0, 0, canvas.width, canvas.height)

    canvas.toBlob(
      (blob) => {
        if (blob) onCapture({ blob, width: canvas.width, height: canvas.height })
      },
      'image/jpeg',
      CAPTURE_QUALITY,
    )
  }, [onCapture])

  if (state === 'denied' || state === 'unavailable' || state === 'error') {
    return (
      <div className="cam cam--blocked">
        <p className="cam__notice" role="status">
          <Icon name={state === 'denied' ? 'lock' : 'alert'} size={16} />
          <span>{message}</span>
        </p>
        <div className="cam__blocked-actions">
          {state !== 'unavailable' ? (
            <Button icon="refresh" onClick={() => setAttempt((value) => value + 1)}>
              Try the camera again
            </Button>
          ) : null}
          {fallback}
        </div>
      </div>
    )
  }

  return (
    <div className="cam">
      <div className="cam__stage">
        <video
          ref={videoRef}
          className="cam__video"
          autoPlay
          playsInline
          muted
          // The preview carries no information a screen reader can use, and the
          // shutter button below is the labelled control.
          aria-hidden
        />
        {state === 'starting' ? <p className="cam__starting">Starting the camera…</p> : null}
      </div>

      <div className="cam__bar">
        <Button variant="primary" icon="scan" disabled={state !== 'live'} onClick={capture}>
          {shutterLabel}
        </Button>
        {fallback}
      </div>
    </div>
  )
}
