import { Link } from 'react-router'
import { ErrorState } from '@/components/ErrorState'
import { Button } from '@/components/ui/button'

export function NotFoundPage() {
  return (
    <div className="flex min-h-svh items-center justify-center">
      <title>Page not found · React SaaS</title>
      <ErrorState title="Page not found" description="There's nothing at this address.">
        <Button asChild>
          <Link to="/">Go home</Link>
        </Button>
      </ErrorState>
    </div>
  )
}
