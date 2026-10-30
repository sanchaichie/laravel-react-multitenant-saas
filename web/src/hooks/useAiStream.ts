import { useCallback, useEffect, useReducer, useRef } from 'react'
import { apiStream } from '@/lib/apiStream'

export type AiStreamStatus = 'idle' | 'streaming' | 'done' | 'error' | 'stopped'

interface State {
  status: AiStreamStatus
  text: string
  /** The message of an error event (or a stream that broke off), when status is 'error'. */
  error: string | null
}

type Action =
  | { type: 'start' }
  | { type: 'delta'; text: string }
  | { type: 'done' }
  | { type: 'error'; message: string }
  | { type: 'stopped' }
  | { type: 'reset' }

const idle: State = { status: 'idle', text: '', error: null }

// Every change in one place (like a small store's mutations): the fields change together.
function reducer(state: State, action: Action): State {
  switch (action.type) {
    case 'start':
      return { status: 'streaming', text: '', error: null }
    case 'delta':
      return { ...state, text: state.text + action.text }
    case 'done':
      return { ...state, status: 'done' }
    case 'error':
      return { ...state, status: 'error', error: action.message }
    case 'stopped':
      return state.status === 'streaming' ? { ...state, status: 'stopped' } : state
    case 'reset':
      return idle
  }
}

/**
 * One streamed AI answer at a time (server-sent events: delta, done, error).
 * start() resolves when the stream ends, and rejects only when the request itself
 * is refused (ApiError: 402, 422, 429, ...), so the caller can say why. Starting
 * again, stop(), reset() or unmounting cancel the running stream; a cancelled one
 * never changes the state again.
 */
export function useAiStream() {
  const [state, dispatch] = useReducer(reducer, idle)
  // The running stream's controller. A ref, not state: changing it shouldn't re-render.
  const current = useRef<AbortController | null>(null)

  // Leaving the page or closing the dialog stops the answer (the server then stops the AI call).
  useEffect(() => () => current.current?.abort(), [])

  const start = useCallback(async (path: string, body: unknown) => {
    current.current?.abort()
    const controller = new AbortController()
    current.current = controller
    const isCurrent = () => current.current === controller
    let ended = false
    dispatch({ type: 'start' })

    try {
      await apiStream(path, {
        body,
        signal: controller.signal,
        onEvent: ({ event, data }) => {
          if (!isCurrent()) return
          if (event === 'delta' && isText(data)) dispatch({ type: 'delta', text: data.text })
          if (event === 'done') {
            ended = true
            dispatch({ type: 'done' })
          }
          if (event === 'error') {
            ended = true
            dispatch({ type: 'error', message: isMessage(data) ? data.message : 'The AI request failed.' })
          }
        },
      })
      if (isCurrent() && !ended) {
        dispatch({ type: 'error', message: 'The answer broke off. Try again.' })
      }
    } catch (error) {
      if (!isCurrent() || controller.signal.aborted) return
      // Refused before streaming (or the connection failed): back to idle, the caller says why.
      dispatch({ type: 'reset' })
      throw error
    } finally {
      if (isCurrent()) current.current = null
    }
  }, [])

  const stop = useCallback(() => {
    current.current?.abort()
    current.current = null
    dispatch({ type: 'stopped' })
  }, [])

  const reset = useCallback(() => {
    current.current?.abort()
    current.current = null
    dispatch({ type: 'reset' })
  }, [])

  return { ...state, start, stop, reset }
}

function isText(data: unknown): data is { text: string } {
  return typeof data === 'object' && data !== null && typeof (data as { text?: unknown }).text === 'string'
}

function isMessage(data: unknown): data is { message: string } {
  return typeof data === 'object' && data !== null && typeof (data as { message?: unknown }).message === 'string'
}
