import { act, renderHook, waitFor } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { logIn, mockApi } from '@/test/utils'
import { useAiStream } from './useAiStream'

const encoder = new TextEncoder()
const event = (name: string, data: unknown) => encoder.encode(`event: ${name}\ndata: ${JSON.stringify(data)}\n\n`)

/** A streamed answer the test writes to by hand; it notes when the request was aborted. */
function manualStream() {
  let controller!: ReadableStreamDefaultController<Uint8Array>
  const handle = { aborted: false, send: (chunk: Uint8Array) => controller.enqueue(chunk), close: () => controller.close() }
  const respond = (init: RequestInit) => {
    init.signal?.addEventListener('abort', () => {
      handle.aborted = true
    })
    const body = new ReadableStream<Uint8Array>({ start: (c) => void (controller = c) })
    return new Response(body, { status: 200, headers: { 'Content-Type': 'text/event-stream' } })
  }
  return { handle, respond }
}

describe('useAiStream', () => {
  it('ignores a stopped stream\'s late events once a new one has started', async () => {
    logIn()
    const first = manualStream()
    const second = manualStream()
    let calls = 0
    mockApi({ 'POST /api/stream': (init) => (++calls === 1 ? first.respond(init) : second.respond(init)) })
    const { result } = renderHook(() => useAiStream())

    act(() => void result.current.start('/api/stream', {}))
    await waitFor(() => expect(calls).toBe(1))
    act(() => first.handle.send(event('delta', { text: 'Old ' })))
    await waitFor(() => expect(result.current.text).toBe('Old '))

    act(() => result.current.stop())
    expect(result.current.status).toBe('stopped')
    expect(first.handle.aborted).toBe(true)

    act(() => void result.current.start('/api/stream', {}))
    await waitFor(() => expect(calls).toBe(2))
    act(() => {
      second.handle.send(event('delta', { text: 'New' }))
      second.handle.send(event('done', { model: 'm' }))
      second.handle.close()
    })

    await waitFor(() => expect(result.current.status).toBe('done'))
    expect(result.current.text).toBe('New')
  })

  it('aborts the request when the component unmounts', async () => {
    logIn()
    const stream = manualStream()
    mockApi({ 'POST /api/stream': (init) => stream.respond(init) })
    const { result, unmount } = renderHook(() => useAiStream())

    act(() => void result.current.start('/api/stream', {}))
    await waitFor(() => expect(result.current.status).toBe('streaming'))
    unmount()

    expect(stream.handle.aborted).toBe(true)
  })
})
