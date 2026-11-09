import { useEffect, useMemo, useRef } from 'react'

/**
 * `callback`, run `delay` ms after the last call (typing in a search box). The
 * latest callback is always the one run; `cancel()` drops a pending call.
 * (The same idea as web/src/hooks/useDebouncedCallback.ts.)
 */
export function useDebouncedCallback<Args extends unknown[]>(callback: (...args: Args) => void, delay: number) {
  const latest = useRef(callback)
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)

  useEffect(() => {
    latest.current = callback
  })

  useEffect(() => () => clearTimeout(timer.current), [])

  return useMemo(() => {
    const debounced = (...args: Args) => {
      clearTimeout(timer.current)
      timer.current = setTimeout(() => latest.current(...args), delay)
    }
    debounced.cancel = () => clearTimeout(timer.current)
    return debounced
  }, [delay])
}
