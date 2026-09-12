/**
 * The two things Pulse says when it cannot answer.
 *
 * Both are here because both are shared by the panel and the action menu, and
 * because they are the parts of this feature most likely to be got wrong
 * separately in two places.
 *
 * {@link PulseUnavailableLine} is the whole of what a deployment without Pulse
 * shows: one sentence, no control, no "coming soon". Pulse is not wired to a
 * provider anywhere today (see `docs/PULSE_INTEGRATION.md`), so a teaser would
 * be advertising something nobody can switch on.
 *
 * {@link PulseFailureNotice} keeps the server's own words. A 503 says the
 * capability is off and a 429 says how long to wait; both are more useful than
 * "Something went wrong", and neither survives being funnelled into a generic
 * error box.
 */

import { Icon } from '../../../shared/ui/Icon'
import { Button } from '../../../shared/ui/primitives'
import { describePulseFailure } from '../hooks/usePulse'
import '../pulse.css'

/** What a deployment without Pulse says, wherever somebody looks for it. */
export const PULSE_UNAVAILABLE = 'Pulse is not enabled on this deployment.'

export function PulseUnavailableLine({ className = '' }: { className?: string }) {
  return <p className={`pulse-off ${className}`.trim()}>{PULSE_UNAVAILABLE}</p>
}

export interface PulseFailureNoticeProps {
  error: unknown
  /** Offered only where asking again could plausibly work. */
  onRetry?: () => void
}

export function PulseFailureNotice({ error, onRetry }: PulseFailureNoticeProps) {
  const failure = describePulseFailure(error)
  if (failure === null) return null

  return (
    <div className="pulse-notice" role="alert">
      <Icon name={failure.icon} size={15} className="pulse-notice__icon" />
      <div className="pulse-notice__body">
        <p className="pulse-notice__message">{failure.message}</p>
        {failure.detail ? <p className="pulse-notice__detail">{failure.detail}</p> : null}
        {onRetry && failure.retryable ? (
          <Button size="sm" icon="refresh" onClick={onRetry}>
            Try again
          </Button>
        ) : null}
      </div>
    </div>
  )
}
