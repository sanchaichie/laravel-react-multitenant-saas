import { act, renderHook, waitFor } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ApiError } from '@/lib/errors'
import { json, manualStream, mockFetch, sseEvent, sseResponse } from '@/test/next'
import { useAiStream } from './use-ai-stream'

describe('useAiStream', () => {
  it('adds the text as it arrives and ends done, sending the organization', async () => {
    const stream = manualStream()
    const calls = mockFetch({ 'POST /api/tasks/1/chat': (init) => stream.respond(init) })
    const { result } = renderHook(() => useAiStream(10))

    let end: Promise<unknown> = Promise.resolve()
    act(() => {
      end = result.current.start('/api/tasks/1/chat', { message: 'Hi' })
    })
    await waitFor(() => expect(result.current.status).toBe('streaming'))
    act(() => stream.handle.send(sseEvent('delta', { text: 'Hello ' })))
    await waitFor(() => expect(result.current.text).toBe('Hello '))
    act(() => {
      stream.handle.send(sseEvent('delta', { text: 'there' }))
      stream.handle.send(sseEvent('done', { model: 'm' }))
      stream.handle.close()
    })

    await expect(end).resolves.toEqual({ status: 'done' })
    await waitFor(() => expect(result.current).toMatchObject({ status: 'done', text: 'Hello there' }))
    const headers = new Headers(calls[0].init.headers)
    expect(headers.get('x-organization-id')).toBe('10')
    expect(headers.get('accept')).toBe('text/event-stream')
  })

  it('reports an error event', async () => {
    mockFetch({ 'POST /api/stream': () => sseResponse(['Part'], { error: 'The AI request failed.' }) })
    const { result } = renderHook(() => useAiStream(10))

    let end: unknown
    await act(async () => {
      end = await result.current.start('/api/stream', {})
    })

    expect(end).toEqual({ status: 'error', message: 'The AI request failed.' })
    expect(result.current).toMatchObject({ status: 'error', text: 'Part', error: 'The AI request failed.' })
  })

  it('says so when the stream breaks off without done', async () => {
    const stream = manualStream()
    mockFetch({ 'POST /api/stream': (init) => stream.respond(init) })
    const { result } = renderHook(() => useAiStream(10))

    let end: Promise<unknown> = Promise.resolve()
    act(() => {
      end = result.current.start('/api/stream', {})
    })
    await waitFor(() => expect(result.current.status).toBe('streaming'))
    act(() => {
      stream.handle.send(sseEvent('delta', { text: 'Half' }))
      stream.handle.close()
    })

    await expect(end).resolves.toEqual({ status: 'error', message: 'The answer broke off. Try again.' })
  })

  it('stops: aborts the request and ignores anything after', async () => {
    const stream = manualStream()
    mockFetch({ 'POST /api/stream': (init) => stream.respond(init) })
    const { result } = renderHook(() => useAiStream(10))

    let end: Promise<unknown> = Promise.resolve()
    act(() => {
      end = result.current.start('/api/stream', {})
    })
    await waitFor(() => expect(result.current.status).toBe('streaming'))
    act(() => stream.handle.send(sseEvent('delta', { text: 'Some ' })))
    await waitFor(() => expect(result.current.text).toBe('Some '))

    act(() => result.current.stop())

    await expect(end).resolves.toEqual({ status: 'cancelled' })
    expect(stream.handle.aborted).toBe(true)
    expect(result.current).toMatchObject({ status: 'stopped', text: 'Some ' })
  })

  it('aborts the request when the component unmounts', async () => {
    const stream = manualStream()
    mockFetch({ 'POST /api/stream': (init) => stream.respond(init) })
    const { result, unmount } = renderHook(() => useAiStream(10))

    act(() => void result.current.start('/api/stream', {}))
    await waitFor(() => expect(result.current.status).toBe('streaming'))
    unmount()

    expect(stream.handle.aborted).toBe(true)
  })

  it('rejects when the request is refused before streaming, and goes back to idle', async () => {
    mockFetch({ 'POST /api/stream': () => json({ message: 'Budget used up.', code: 'ai_budget_exceeded' }, 402) })
    const { result } = renderHook(() => useAiStream(10))

    let error: unknown
    await act(async () => {
      error = await result.current.start('/api/stream', {}).catch((e: unknown) => e)
    })

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).status).toBe(402)
    expect(result.current.status).toBe('idle')
  })
})
