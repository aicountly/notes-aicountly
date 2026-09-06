/**
 * The recorder's rules, without a microphone.
 *
 * The reducer is where the behaviour people notice lives — a clock that keeps
 * running while paused, a Stop that still works after the clip is finished, a
 * failed upload that throws the recording away — so it is tested directly. The
 * rendered panel is checked for the two promises the feature flag makes.
 */

import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'

import {
  INITIAL_RECORDER_STATE,
  VoiceRecorder,
  baseMimeType,
  formatClock,
  isVoiceRecordingSupported,
  recorderReducer,
} from './VoiceRecorder'
import type { RecorderEvent, RecorderState } from './VoiceRecorder'

/** Drive the machine through a list of events, like a session would. */
function run(events: RecorderEvent[], from: RecorderState = INITIAL_RECORDER_STATE): RecorderState {
  return events.reduce(recorderReducer, from)
}

describe('recorderReducer', () => {
  it('walks from idle to a finished clip', () => {
    const state = run([
      { type: 'request' },
      { type: 'granted' },
      { type: 'tick', deltaMs: 1000 },
      { type: 'tick', deltaMs: 1000 },
      { type: 'stop' },
    ])

    expect(state.status).toBe('recorded')
    expect(state.elapsedMs).toBe(2000)
  })

  it('does not count paused time as recorded audio', () => {
    const state = run([
      { type: 'request' },
      { type: 'granted' },
      { type: 'tick', deltaMs: 1000 },
      { type: 'pause' },
      { type: 'tick', deltaMs: 1000 },
      { type: 'tick', deltaMs: 1000 },
      { type: 'resume' },
      { type: 'tick', deltaMs: 1000 },
    ])

    expect(state.status).toBe('recording')
    expect(state.elapsedMs).toBe(2000)
  })

  it('ignores events that do not apply to the current state', () => {
    expect(run([{ type: 'stop' }]).status).toBe('idle')
    expect(run([{ type: 'pause' }]).status).toBe('idle')
    expect(run([{ type: 'request' }, { type: 'resume' }]).status).toBe('requesting')
  })

  it('cannot start a second recording while one is running', () => {
    const recording = run([{ type: 'request' }, { type: 'granted' }, { type: 'tick', deltaMs: 5000 }])
    const after = recorderReducer(recording, { type: 'request' })

    expect(after).toBe(recording)
  })

  it('reports a permission denial with its explanation and no clock', () => {
    const state = run([
      { type: 'request' },
      { type: 'denied', message: 'Allow microphone access.' },
    ])

    expect(state.status).toBe('denied')
    expect(state.message).toBe('Allow microphone access.')
    expect(state.elapsedMs).toBe(0)
  })

  it('keeps the recording when saving it fails', () => {
    const state = run([
      { type: 'request' },
      { type: 'granted' },
      { type: 'tick', deltaMs: 3000 },
      { type: 'stop' },
      { type: 'save' },
      { type: 'saveFailed', message: 'You appear to be offline.' },
    ])

    expect(state.status).toBe('recorded')
    expect(state.message).toBe('You appear to be offline.')
    expect(state.elapsedMs).toBe(3000)
  })

  it('resets once the clip is stored', () => {
    const state = run([
      { type: 'request' },
      { type: 'granted' },
      { type: 'tick', deltaMs: 3000 },
      { type: 'stop' },
      { type: 'save' },
      { type: 'saved' },
    ])

    expect(state).toEqual(INITIAL_RECORDER_STATE)
  })
})

describe('formatClock', () => {
  it('is mm:ss, zero padded', () => {
    expect(formatClock(0)).toBe('00:00')
    expect(formatClock(9_400)).toBe('00:09')
    expect(formatClock(75_000)).toBe('01:15')
    expect(formatClock(3_600_000)).toBe('60:00')
  })
})

describe('baseMimeType', () => {
  it('drops the codec parameter the server does not want', () => {
    expect(baseMimeType('audio/webm;codecs=opus')).toBe('audio/webm')
    expect(baseMimeType('audio/mp4')).toBe('audio/mp4')
  })
})

describe('isVoiceRecordingSupported', () => {
  it('is false where the browser cannot record', () => {
    // jsdom has neither MediaRecorder nor mediaDevices, which is exactly the
    // shape of an insecure origin or an old Safari.
    expect(isVoiceRecordingSupported()).toBe(false)
  })
})

describe('<VoiceRecorder />', () => {
  const noop = async () => undefined

  it('says plainly that transcription is off rather than promising a transcript', () => {
    render(<VoiceRecorder onSave={noop} onCancel={() => undefined} />)

    expect(
      screen.getByText(/Transcription is not enabled on this deployment/i),
    ).toBeInTheDocument()
  })

  it('offers only the controls that apply before anything is recorded', () => {
    render(<VoiceRecorder onSave={noop} onCancel={() => undefined} />)

    expect(screen.getByRole('button', { name: 'Start recording' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Stop' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Pause' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Attach to note' })).not.toBeInTheDocument()
  })

  it('shows the clock at zero', () => {
    render(<VoiceRecorder onSave={noop} onCancel={() => undefined} />)

    expect(screen.getByText('00:00')).toBeInTheDocument()
  })
})
