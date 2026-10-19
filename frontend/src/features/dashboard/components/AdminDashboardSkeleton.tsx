import { Skeleton } from '@/components/ui'

/** The overview's first load, shaped like what arrives — so nothing jumps. */
export function AdminDashboardSkeleton() {
  return (
    <div className="flex flex-col gap-8" aria-busy="true" aria-label="Loading the ticket overview">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {Array.from({ length: 4 }).map((_, index) => (
          <Skeleton key={index} className="h-28 rounded-md" />
        ))}
      </div>
      <div className="grid grid-cols-1 gap-6 xl:grid-cols-12">
        <Skeleton className="h-[26rem] rounded-lg xl:col-span-7" />
        <Skeleton className="h-[26rem] rounded-lg xl:col-span-5" />
      </div>
      <Skeleton className="h-72 rounded-lg" />
    </div>
  )
}
