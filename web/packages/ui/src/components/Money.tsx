import { cn } from '../lib/cn'
import { formatBs, formatUsd, type Cents, type Rate } from '../lib/money'

type Scale = 'sm' | 'md' | 'lg' | 'xl'

interface MoneyProps {
  cents: Cents
  /** Si se pasa, debajo aparece el equivalente en bolívares. */
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
 * Un importe.
 *
 * **El número es el protagonista**: el total, el vuelto y el saldo van en la
 * mayor escala de su pantalla. Y en cifras de ancho fijo (`tabular`), porque
 * sin eso una columna de importes baila al actualizarse y cuesta compararla de
 * un vistazo.
 *
 * El dólar arriba y el bolívar debajo, más pequeño: el dólar es la moneda de
 * valor y el bolívar la de cobro. Invertirlo haría que el número que manda
 * cambie solo cada mañana.
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
