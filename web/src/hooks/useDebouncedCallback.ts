import { useEffect, useMemo, useRef } from 'react'

export interface DebouncedFunction<A extends unknown[]> {
  (...args: A): void
  /** Drop a call that's still waiting (e.g. the filters were cleared meanwhile). */
  cancel: () => void
}

/**
 * `callback`, called only once `delay` ms have passed without another call
 * (watchDebounced in VueUse). The timer lives in a ref, so it survives re-renders
 * without causing one; the effect's cleanup clears it when the component unmounts.
 */
export function useDebouncedCallback<A extends unknown[]>(callback: (...args: A) => void, delay: number): DebouncedFunction<A> {
  const callbackRef = useRef(callback)
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)

  // Always call the latest callback (it may close over newer props), without
  // making a new debounced function each render.
  useEffect(() => {
    callbackRef.current = callback
  })

  useEffect(() => () => clearTimeout(timer.current), [])

  return useMemo(() => {
    const debounced = (...args: A) => {
      clearTimeout(timer.current)
      timer.current = setTimeout(() => callbackRef.current(...args), delay)
    }
    debounced.cancel = () => clearTimeout(timer.current)

    return debounced
  }, [delay])
}
