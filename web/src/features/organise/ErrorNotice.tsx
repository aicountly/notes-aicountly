/**
 * How a failed read or write is shown in the organising sections.
 *
 * Two things it insists on. The message comes from the server, because
 * "Something went wrong" is never as useful as "You already have a notebook
 * with that name in this place". And being offline is its own state with its
 * own words — it is an expected outcome of using this app on a train, not an
 * error the user did something to cause.
 */

import { ApiError } from '../../shared/api/client'
import { Icon } from '../../shared/ui/Icon'
import { Button } from '../../shared/ui/primitives'
import './organise.css'

/** The server's sentence where there is one, and a plain fallback where not. */
export function describeError(error: unknown): string {
  if (error instanceof ApiError) return error.message
  return 'Something went wrong. Please try again.'
}

export function ErrorNotice({
  error,
  onRetry,
  retryLabel = 'Try again',
  onDismiss,
}: {
  error: unknown
  onRetry?: () => void
  retryLabel?: string
  /**
   * Offered where the notice reports a *write* that failed rather than a read
   * that can be re-run. Without it the sentence sits in the sidebar for the
   * rest of the session with nothing the user can do about it.
   */
  onDismiss?: () => void
}) {
  const offline = error instanceof ApiError && error.isOffline

  return (
    <div className={`org-notice ${offline ? 'org-notice--offline' : ''}`.trim()} role="alert">
      <Icon name={offline ? 'cloud-off' : 'alert'} size={15} className="org-notice__icon" />
      <div className="org-notice__body">
        {describeError(error)}
        {onRetry || onDismiss ? (
          <div className="org-notice__actions">
            {onRetry ? (
              <Button size="sm" icon="refresh" onClick={onRetry}>
                {retryLabel}
              </Button>
            ) : null}
            {onDismiss ? (
              <Button size="sm" variant="ghost" icon="close" onClick={onDismiss}>
                Dismiss
              </Button>
            ) : null}
          </div>
        ) : null}
      </div>
    </div>
  )
}
