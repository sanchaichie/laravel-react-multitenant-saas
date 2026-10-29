import { renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useDebouncedCallback } from './useDebouncedCallback'

describe('useDebouncedCallback', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('calls once, with the last arguments, after a pause', () => {
    const callback = vi.fn()
    const { result } = renderHook(() => useDebouncedCallback(callback, 300))

    result.current('l')
    vi.advanceTimersByTime(200)
    result.current('la')
    vi.advanceTimersByTime(299)
    expect(callback).not.toHaveBeenCalled()

    vi.advanceTimersByTime(1)
    expect(callback).toHaveBeenCalledOnce()
    expect(callback).toHaveBeenCalledWith('la')
  })

  it('calls the latest callback, and keeps the same function across renders', () => {
    const first = vi.fn()
    const second = vi.fn()
    const { result, rerender } = renderHook(({ callback }) => useDebouncedCallback(callback, 300), { initialProps: { callback: first } })
    const debounced = result.current

    debounced('x')
    rerender({ callback: second })
    vi.advanceTimersByTime(300)

    expect(result.current).toBe(debounced)
    expect(first).not.toHaveBeenCalled()
    expect(second).toHaveBeenCalledWith('x')
  })

  it('can be cancelled, and is cancelled on unmount', () => {
    const callback = vi.fn()
    const { result, unmount } = renderHook(() => useDebouncedCallback(callback, 300))

    result.current('a')
    result.current.cancel()
    vi.advanceTimersByTime(300)
    result.current('b')
    unmount()
    vi.advanceTimersByTime(300)

    expect(callback).not.toHaveBeenCalled()
  })
})
