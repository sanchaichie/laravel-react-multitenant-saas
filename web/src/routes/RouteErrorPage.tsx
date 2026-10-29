import { useEffect } from 'react'
import { useRouteError } from 'react-router'
import { ErrorState } from '@/components/ErrorState'
import { Button } from '@/components/ui/button'

/** Shown instead of a blank page when a page throws while rendering. */
export function RouteErrorPage() {
  const error = useRouteError()

  // Logging is a side effect: in an effect, not the render body (which may run twice).
  useEffect(() => {
    console.error(error)
  }, [error])

  return (
    <div className="flex min-h-svh items-center justify-center">
      <title>Something went wrong · React SaaS</title>
      <ErrorState title="Something went wrong" description="This page couldn't be shown.">
        <Button onClick={() => window.location.reload()}>Reload</Button>
      </ErrorState>
    </div>
  )
}
