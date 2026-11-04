import { useEffect } from 'react'
import { EyeIcon } from 'lucide-react'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'
import { formatTime } from '@/lib/format'
import { useAuth } from '@/stores/auth'
import { useStopSupportView } from './api'

/**
 * At the top of every page while a platform admin views the app as a user: whose
 * account, that it's read-only, when it ends, and Stop viewing.
 */
export function SupportViewBanner() {
  const user = useAuth((s) => s.user)
  const stop = useStopSupportView()
  const session = user?.impersonated_by

  if (!user || !session) {
    return null
  }

  return (
    // Theme tokens (primary), so it reads in light and dark mode and stands out from the page.
    <div role="status" className="flex flex-wrap items-center gap-x-3 gap-y-2 bg-primary px-4 py-2 text-sm text-primary-foreground">
      <EyeIcon className="size-4 shrink-0" aria-hidden />
      <p className="min-w-0 flex-1">
        Viewing as <span className="font-semibold break-words">{user.name}</span> (read-only) · ends at {formatTime(session.expires_at)}
      </p>
      <Button size="sm" variant="secondary" onClick={() => stop.mutate()} disabled={stop.isPending}>
        {stop.isPending && <Spinner />}
        Stop viewing
      </Button>
    </div>
  )
}

/**
 * When a support view ended (Stop viewing, or on its own: expired or ended on the
 * server), the store is already back on the admin: leave the user's pages for their
 * admin page, and say so if it wasn't Stop. An effect, on purpose: it reacts to a
 * store change made outside React (the API layer), and it runs after the pages' own
 * effects, so a page's redirect on the organization change (a task's) can't win.
 */
export function SupportViewEndedNotice() {
  const ended = useAuth((s) => s.supportViewEnded)
  const seen = useAuth((s) => s.supportViewEndedSeen)
  const navigate = useNavigate()

  useEffect(() => {
    if (ended) {
      seen()
      if (ended.expired) {
        toast.info('The support view ended.')
      }
      void navigate(ended.userId ? `/admin/users/${ended.userId}` : '/admin/users', { replace: ended.expired })
    }
  }, [ended, seen, navigate])

  return null
}
