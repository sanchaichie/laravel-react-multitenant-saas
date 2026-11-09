import { Alert, AlertDescription } from '@/components/ui/alert'

/** A message for the whole form (not one field): a failed request, or what happened. */
export function FormMessage({ message, variant = 'destructive' }: { message?: string; variant?: 'destructive' | 'success' | 'info' }) {
  if (!message) {
    return null
  }

  return (
    <Alert variant={variant} role={variant === 'destructive' ? 'alert' : 'status'}>
      <AlertDescription>{message}</AlertDescription>
    </Alert>
  )
}
