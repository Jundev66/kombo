import { cn } from '../lib/cn'
import { formatBs, formatUsd, type Cents, type Rate } from '../lib/money'

type Scale = 'sm' | 'md' | 'lg' | 'xl'

interface MoneyProps {
  cents: Cents
  /** When given, the bolívar equivalent appears underneath. */
  rate?: Rate | null
  scale?: Scale
  className?: string
}

const SCALES: Record<Scale, string> = {
  sm: 'text-money-sm',
  md: 'text-money',
  lg: 'text-money-lg',
  xl: 'text-money-xl',
}

/**
 * An amount.
 *
 * The number is the protagonist: the total, the change and the balance go in
 * the largest scale on their screen, in tabular figures — without them a column
 * of amounts jitters as it updates and is hard to compare at a glance.
 *
 * The dollar on top and the bolívar below, smaller: the dollar is the unit of
 * value and the bolívar the unit of payment. Inverting them would make the
 * governing number change by itself every morning.
 */
export function Money({ cents, rate, scale = 'md', className }: MoneyProps) {
  return (
    <span className={cn('inline-flex flex-col leading-tight', className)}>
      <span className={cn('tabular font-semibold text-[var(--text-strong)]', SCALES[scale])}>
        {formatUsd(cents)}
      </span>

      {rate != null && rate > 0 && (
        <span className="tabular text-xs text-[var(--text-muted)]">{formatBs(cents, rate)}</span>
      )}
    </span>
  )
}
