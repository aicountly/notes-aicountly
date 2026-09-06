import { Skeleton } from './primitives'

/**
 * What a lazily-loaded screen shows while its chunk arrives.
 *
 * Shaped like a page rather than centred on a spinner, so the layout does not
 * jump when the real content lands.
 */
export function PageFallback() {
  return (
    <div className="page-fallback" aria-busy>
      <Skeleton width={180} height={22} />
      <Skeleton width="70%" height={14} />
      <Skeleton width="55%" height={14} />
      <Skeleton width="62%" height={14} />
    </div>
  )
}
