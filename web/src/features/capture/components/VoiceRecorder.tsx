/**
 * Recording a voice note.
 *
 * The state machine is exported and pure because that is where the bugs live:
 * a clock that keeps counting while the recording is paused, a Stop that is
 * still clickable after the clip is finished, a permission denial that leaves
 * the UI in "recording". Testing the reducer catches all three without a fake
 * microphone.
 *
 * Two rules about what this is allowed to promise:
 *
 *   - When `MediaRecorder` is missing the entry point does not exist. There is
 *     no button that explains it cannot record — {@link isVoiceRecordingSupported}
 *     is checked by the caller before this is ever mounted.
 *   - Transcription is a *deployment* capability. With the flag off the panel
 *     says so in a sentence, rather than uploading the clip and leaving a
 *     "Transcribing…" spinner that nothing will ever finish.
 */

import { useCallback, useEffect, useId, useReducer, useRef, useState } from 'react'

import { useFeature } from '../../../app/AppConfigProvider'
import { Icon } from '../../../shared/ui/Icon'
import { Button, LiveStatus } from '../../../shared/ui/primitives'
import '../capture.css'

// ---------------------------------------------------------------------------
// The state machine
// ---------------------------------------------------------------------------

export type RecorderStatus =
  | 'idle'
  | 'requesting'
  | 'recording'
  | 'paused'
  | 'recorded'
  | 'saving'
  | 'denied'
  | 'error'

export interface RecorderState {
  status: RecorderStatus
  /** Audio captured so far. Paused time is not audio, so it does not count. */
  elapsedMs: number
  message: string | null
}

export type RecorderEvent =
  | { type: 'request' }
  | { type: 'granted' }
  | { type: 'denied'; message: string }
  | { type: 'failed'; message: string }
  | { type: 'tick'; deltaMs: number }
  | { type: 'pause' }
  | { type: 'resume' }
  | { type: 'stop' }
  | { type: 'save' }
  | { type: 'saved' }
  | { type: 'saveFailed'; message: string }
  | { type: 'discard' }

export const INITIAL_RECORDER_STATE: RecorderState = {
  status: 'idle',
  elapsedMs: 0,
  message: null,
}

/**
 * Every transition, in one place.
 *
 * Events that do not apply to the current status are ignored rather than
 * throwing: a second click on Stop while the recorder is finishing is a normal
 * thing for a person to do, not an error.
 */
export function recorderReducer(state: RecorderState, event: RecorderEvent): RecorderState {
  switch (event.type) {
    case 'request':
      if (state.status === 'recording' || state.status === 'paused' || state.status === 'saving') {
        return state
      }
      return { status: 'requesting', elapsedMs: 0, message: null }

    case 'granted':
      return state.status === 'requesting' ? { ...state, status: 'recording' } : state

    case 'denied':
      return { status: 'denied', elapsedMs: 0, message: event.message }

    case 'failed':
      return { ...state, status: 'error', message: event.message }

    case 'tick':
      // Only while the microphone is actually feeding the recorder.
      return state.status === 'recording'
        ? { ...state, elapsedMs: state.elapsedMs + event.deltaMs }
        : state

    case 'pause':
      return state.status === 'recording' ? { ...state, status: 'paused' } : state

    case 'resume':
      return state.status === 'paused' ? { ...state, status: 'recording' } : state

    case 'stop':
      return state.status === 'recording' || state.status === 'paused'
        ? { ...state, status: 'recorded' }
        : state

    case 'save':
      return state.status === 'recorded' ? { ...state, status: 'saving', message: null } : state

    case 'saved':
      return { ...INITIAL_RECORDER_STATE }

    case 'saveFailed':
      // Back to a clip in hand, so the recording is not lost with the upload.
      return { ...state, status: 'recorded', message: event.message }

    case 'discard':
      return { ...INITIAL_RECORDER_STATE }

    default:
      return state
  }
}

// ---------------------------------------------------------------------------
// Capability and format
// ---------------------------------------------------------------------------

/** False wherever the browser cannot record: Safari before 14.1, an http origin. */
export function isVoiceRecordingSupported(): boolean {
  return (
    typeof window !== 'undefined' &&
    typeof window.MediaRecorder !== 'undefined' &&
    typeof navigator !== 'undefined' &&
    typeof navigator.mediaDevices?.getUserMedia === 'function'
  )
}

/**
 * Container preference, and the extension that goes with each.
 *
 * The extension matters more than it looks: the server compares what the name
 * claims against what the bytes turn out to be and refuses a disagreement, so
 * an audio-only WebM has to be `.weba` (audio/webm) and not `.webm`, which
 * claims video.
 */
const AUDIO_FORMATS: Array<{ mimeType: string; extension: string }> = [
  { mimeType: 'audio/webm;codecs=opus', extension: 'weba' },
  { mimeType: 'audio/webm', extension: 'weba' },
  { mimeType: 'audio/ogg;codecs=opus', extension: 'ogg' },
  { mimeType: 'audio/mp4', extension: 'm4a' },
]

export function baseMimeType(mimeType: string): string {
  return mimeType.split(';')[0].trim()
}

/** The first container this browser will actually produce. */
export function chooseAudioFormat(): { mimeType: string; extension: string } | null {
  if (typeof window.MediaRecorder?.isTypeSupported !== 'function') return null
  return AUDIO_FORMATS.find((format) => window.MediaRecorder.isTypeSupported(format.mimeType)) ?? null
}

export function formatClock(milliseconds: number): string {
  const total = Math.floor(milliseconds / 1000)
  const minutes = Math.floor(total / 60)
  const seconds = total % 60
  return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
}

function timestampedName(extension: string): string {
  const now = new Date()
  const pad = (value: number) => String(value).padStart(2, '0')
  // Colons are legal in a filename on Linux and a nuisance everywhere else.
  return `Voice note ${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())} ${pad(now.getHours())}-${pad(now.getMinutes())}.${extension}`
}

// ---------------------------------------------------------------------------
// The component
// ---------------------------------------------------------------------------

const TICK_MS = 200
/** Level meter refresh. Slower under reduced motion — it is data, not decoration. */
const METER_FPS = 30
const METER_FPS_REDUCED = 5

export interface VoiceRecorderProps {
  /** Stores the clip. Rejecting with a message leaves the recording in hand. */
  onSave: (file: File) => Promise<void>
  onCancel: () => void
}

export function VoiceRecorder({ onSave, onCancel }: VoiceRecorderProps) {
  const transcriptionEnabled = useFeature('transcription')
  const [state, dispatch] = useReducer(recorderReducer, INITIAL_RECORDER_STATE)
  const [clipUrl, setClipUrl] = useState<string | null>(null)
  const [announcement, setAnnouncement] = useState('')

  const recorderRef = useRef<MediaRecorder | null>(null)
  const streamRef = useRef<MediaStream | null>(null)
  const chunksRef = useRef<Blob[]>([])
  const formatRef = useRef<{ mimeType: string; extension: string } | null>(null)
  const clipRef = useRef<File | null>(null)
  const audioContextRef = useRef<AudioContext | null>(null)
  const analyserRef = useRef<AnalyserNode | null>(null)
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const frameRef = useRef<number | null>(null)

  /** Everything the microphone is holding open. Called on stop and on unmount. */
  const releaseHardware = useCallback(() => {
    if (frameRef.current !== null) {
      cancelAnimationFrame(frameRef.current)
      frameRef.current = null
    }
    streamRef.current?.getTracks().forEach((track) => track.stop())
    streamRef.current = null
    analyserRef.current = null
    void audioContextRef.current?.close().catch(() => undefined)
    audioContextRef.current = null
  }, [])

  useEffect(() => releaseHardware, [releaseHardware])

  // The object URL for playback outlives a re-render but not the component.
  useEffect(() => {
    return () => {
      if (clipUrl) URL.revokeObjectURL(clipUrl)
    }
  }, [clipUrl])

  // The clock. An interval rather than a timestamp difference because pausing
  // has to stop it, and the reducer already ignores ticks when it is paused.
  useEffect(() => {
    if (state.status !== 'recording') return undefined
    const timer = window.setInterval(() => dispatch({ type: 'tick', deltaMs: TICK_MS }), TICK_MS)
    return () => window.clearInterval(timer)
  }, [state.status])

  /** The level meter, drawn from the analyser rather than from React state. */
  const drawMeter = useCallback(() => {
    const canvas = canvasRef.current
    const analyser = analyserRef.current
    if (!canvas || !analyser) return

    const context = canvas.getContext('2d')
    if (!context) return

    const reduced =
      typeof window.matchMedia === 'function' &&
      window.matchMedia('(prefers-reduced-motion: reduce)').matches
    const interval = 1000 / (reduced ? METER_FPS_REDUCED : METER_FPS)

    const samples = new Uint8Array(analyser.frequencyBinCount)
    // The stroke colour comes from CSS, so the meter follows the theme instead
    // of carrying a second palette.
    const ink = window.getComputedStyle(canvas).color

    let last = 0
    const paint = (now: number) => {
      frameRef.current = requestAnimationFrame(paint)
      if (now - last < interval) return
      last = now

      analyser.getByteTimeDomainData(samples)

      const width = canvas.width
      const height = canvas.height
      context.clearRect(0, 0, width, height)
      context.fillStyle = ink

      const bars = 48
      const step = Math.floor(samples.length / bars) || 1
      const barWidth = width / bars

      for (let bar = 0; bar < bars; bar += 1) {
        // Peak deviation from the 128 midpoint, as a fraction of full scale.
        let peak = 0
        for (let offset = 0; offset < step; offset += 1) {
          const sample = samples[bar * step + offset] ?? 128
          peak = Math.max(peak, Math.abs(sample - 128) / 128)
        }
        const barHeight = Math.max(2, peak * height)
        context.fillRect(
          bar * barWidth + barWidth * 0.2,
          (height - barHeight) / 2,
          Math.max(1, barWidth * 0.6),
          barHeight,
        )
      }
    }

    frameRef.current = requestAnimationFrame(paint)
  }, [])

  const start = useCallback(async () => {
    dispatch({ type: 'request' })
    chunksRef.current = []
    clipRef.current = null
    setClipUrl((current) => {
      if (current) URL.revokeObjectURL(current)
      return null
    })

    let stream: MediaStream
    try {
      stream = await navigator.mediaDevices.getUserMedia({ audio: true })
    } catch (error) {
      const name = error instanceof Error ? error.name : ''
      if (name === 'NotAllowedError' || name === 'SecurityError') {
        dispatch({
          type: 'denied',
          message:
            'This site does not have permission to use the microphone. Allow microphone access for this site in your browser’s address bar, then start again.',
        })
        return
      }
      dispatch({
        type: 'failed',
        message:
          name === 'NotFoundError'
            ? 'No microphone was found on this device.'
            : 'The microphone could not be started.',
      })
      return
    }

    streamRef.current = stream
    const format = chooseAudioFormat()
    formatRef.current = format

    let recorder: MediaRecorder
    try {
      recorder = format
        ? new MediaRecorder(stream, { mimeType: format.mimeType })
        : new MediaRecorder(stream)
    } catch {
      releaseHardware()
      dispatch({ type: 'failed', message: 'This browser could not start a recording.' })
      return
    }

    recorder.ondataavailable = (event) => {
      if (event.data.size > 0) chunksRef.current.push(event.data)
    }
    recorder.onstop = () => {
      const type = baseMimeType(recorder.mimeType || format?.mimeType || 'audio/webm')
      const extension = format?.extension ?? 'weba'
      const blob = new Blob(chunksRef.current, { type })
      const file = new File([blob], timestampedName(extension), { type })

      clipRef.current = file
      setClipUrl(URL.createObjectURL(blob))
      releaseHardware()
      setAnnouncement('Recording finished')
    }

    recorderRef.current = recorder
    recorder.start(1000)

    // The meter reads the same stream; an AudioContext is the only way to get
    // at the samples while MediaRecorder is consuming them.
    try {
      const audioContext = new AudioContext()
      const analyser = audioContext.createAnalyser()
      analyser.fftSize = 2048
      audioContext.createMediaStreamSource(stream).connect(analyser)
      audioContextRef.current = audioContext
      analyserRef.current = analyser
      drawMeter()
    } catch {
      // No meter on a browser without Web Audio. The recording still works,
      // and a missing visualisation is not worth failing the whole flow.
      analyserRef.current = null
    }

    dispatch({ type: 'granted' })
    setAnnouncement('Recording')
  }, [drawMeter, releaseHardware])

  const pause = () => {
    if (recorderRef.current?.state === 'recording') {
      recorderRef.current.pause()
      dispatch({ type: 'pause' })
      setAnnouncement('Recording paused')
    }
  }

  const resume = () => {
    if (recorderRef.current?.state === 'paused') {
      recorderRef.current.resume()
      dispatch({ type: 'resume' })
      setAnnouncement('Recording resumed')
    }
  }

  const stop = () => {
    if (recorderRef.current && recorderRef.current.state !== 'inactive') {
      recorderRef.current.stop()
    }
    dispatch({ type: 'stop' })
  }

  /** Drop the clip and the URL playing it. Shared by Discard and a good save. */
  const clearClip = useCallback(() => {
    setClipUrl((current) => {
      if (current) URL.revokeObjectURL(current)
      return null
    })
    clipRef.current = null
    chunksRef.current = []
  }, [])

  const discard = () => {
    clearClip()
    dispatch({ type: 'discard' })
    setAnnouncement('Recording discarded')
  }

  const save = async () => {
    const file = clipRef.current
    if (!file) return

    dispatch({ type: 'save' })
    try {
      await onSave(file)
      clearClip()
      dispatch({ type: 'saved' })
      setAnnouncement('Voice note attached')
    } catch (error) {
      dispatch({
        type: 'saveFailed',
        message: error instanceof Error ? error.message : 'That recording could not be attached.',
      })
    }
  }

  const playbackId = useId()
  const recording = state.status === 'recording'
  const paused = state.status === 'paused'
  const live = recording || paused

  return (
    <div className="voice">
      <div className="voice__stage">
        <canvas
          ref={canvasRef}
          className={`voice__meter ${recording ? 'voice__meter--live' : ''}`.trim()}
          width={480}
          height={64}
          aria-hidden
        />
        <p className="voice__clock">
          <span className="sr-only">Recorded length</span>
          {/* Not a live region: a duration read out every second would talk
              over everything else a screen reader is saying. */}
          <output aria-live="off">{formatClock(state.elapsedMs)}</output>
          {paused ? <span className="voice__paused-flag">Paused</span> : null}
        </p>
      </div>

      {state.message ? (
        <p className="voice__notice" role="alert">
          <Icon name={state.status === 'denied' ? 'lock' : 'alert'} size={15} />
          <span>{state.message}</span>
        </p>
      ) : null}

      {!transcriptionEnabled ? (
        <p className="voice__notice voice__notice--info">
          <Icon name="info" size={15} />
          <span>
            Transcription is not enabled on this deployment. The recording is saved as an audio
            file you can play back, and no transcript is produced.
          </span>
        </p>
      ) : null}

      {clipUrl ? (
        <div className="voice__playback">
          <label className="voice__playback-label" htmlFor={playbackId}>
            Listen back before saving
          </label>
          <audio id={playbackId} className="voice__audio" src={clipUrl} controls />
        </div>
      ) : null}

      <div className="voice__bar">
        {!live && state.status !== 'recorded' && state.status !== 'saving' ? (
          <Button variant="primary" icon="mic" loading={state.status === 'requesting'} onClick={() => void start()}>
            {state.status === 'denied' || state.status === 'error' ? 'Try again' : 'Start recording'}
          </Button>
        ) : null}

        {recording ? (
          <Button icon="pause" onClick={pause}>
            Pause
          </Button>
        ) : null}
        {paused ? (
          <Button icon="play" onClick={resume}>
            Resume
          </Button>
        ) : null}
        {live ? (
          <Button variant="primary" icon="stop" onClick={stop}>
            Stop
          </Button>
        ) : null}

        {state.status === 'recorded' || state.status === 'saving' ? (
          <>
            <Button
              variant="primary"
              icon="check"
              loading={state.status === 'saving'}
              onClick={() => void save()}
            >
              Attach to note
            </Button>
            <Button icon="trash" disabled={state.status === 'saving'} onClick={discard}>
              Discard
            </Button>
          </>
        ) : null}

        <span className="voice__spacer" />
        <Button variant="ghost" onClick={onCancel} disabled={state.status === 'saving'}>
          Close
        </Button>
      </div>

      <LiveStatus>{announcement}</LiveStatus>
    </div>
  )
}
