import { Skeleton } from '@/components/ui/skeleton'

/** loading.tsx: shown at once while a page's Server Component fetches (a Suspense fallback). */
export default function Loading() {
  return (
    <div className="grid gap-4" aria-label="Loading">
      <Skeleton className="h-8 w-1/3" />
      <Skeleton className="h-28 w-full" />
      <Skeleton className="h-64 w-full" />
    </div>
  )
}
