import { apiRequest } from '@/lib/api'
import { parseSseChunk, type SseEvent } from '@/lib/sse'

interface ApiStreamOptions {
  body: unknown
  signal: AbortSignal
  /** Called for each event as it arrives. */
  onEvent: (event: SseEvent) => void
}

/**
 * POSTs to an endpoint that answers with server-sent events (AI writing help) and
 * hands each event to onEvent until the stream ends. Not EventSource: that can't
 * send the Bearer token or a body. The request goes through apiRequest, so a 4xx
 * before streaming (403, 422, 402, 429) throws ApiError like any call, and a 401
 * ends the session. Once the answer has started, a dropped connection just ends it
 * (the caller sees it never got done or error); only an abort by the caller throws.
 */
export async function apiStream(path: string, { body, signal, onEvent }: ApiStreamOptions): Promise<void> {
  const response = await apiRequest(path, { method: 'POST', body, signal, accept: 'text/event-stream' })
  if (!response.body) {
    throw new Error('The answer had no body.')
  }

  const reader = response.body.getReader()
  const decoder = new TextDecoder()
  let rest = ''

  try {
    for (;;) {
      const { done, value } = await reader.read()
      if (done) break
      // stream: true keeps a character split across chunks for the next decode.
      const parsed = parseSseChunk(rest, decoder.decode(value, { stream: true }))
      rest = parsed.rest
      parsed.events.forEach(onEvent)
    }
  } catch (error) {
    if (signal.aborted) throw error
    return
  }

  // A last event without the blank line after it.
  parseSseChunk(rest, decoder.decode() + '\n\n').events.forEach(onEvent)
}
