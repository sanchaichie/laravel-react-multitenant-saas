import { Skeleton } from '@/components/ui/skeleton'

export function AdminListSkeleton({ label }: { label: string }) {
  return (
    <div className="grid gap-2" aria-label={label}>
      <Skeleton className="h-9 w-full" />
      <Skeleton className="h-9 w-full" />
      <Skeleton className="h-9 w-full" />
    </div>
  )
}
