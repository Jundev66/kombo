import type { ReactNode } from 'react'
import { cn } from '../lib/cn'

export function Card({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <div
      className={cn(
        'rounded-[var(--radius-lg)] bg-[var(--surface-raised)] shadow-[var(--shadow-card)]',
        className,
      )}
    >
      {children}
    </div>
  )
}

type Tone = 'neutral' | 'ok' | 'warn' | 'bad'

const TONES: Record<Tone, string> = {
  neutral: 'bg-[var(--surface-sunken)] text-[var(--text-muted)]',
  ok: 'bg-ok-50 text-ok-700',
  warn: 'bg-warn-50 text-warn-700',
  bad: 'bg-bad-50 text-bad-700',
}

export function Badge({ children, tone = 'neutral' }: { children: ReactNode; tone?: Tone }) {
  return (
    <span
      className={cn(
        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
        TONES[tone],
      )}
    >
      {children}
    </span>
  )
}

/**
 * Qué se ve cuando no hay nada.
 *
 * Siempre con la acción al lado. Una lista vacía que sólo dice «no hay
 * productos» deja a alguien buscando dónde se crean; una que trae el botón
 * resuelve el problema en el sitio donde apareció.
 */
export function EmptyState({
  title,
  description,
  action,
}: {
  title: string
  description?: string
  action?: ReactNode
}) {
  return (
    <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
      <p className="font-medium text-[var(--text-strong)]">{title}</p>
      {description !== undefined && (
        <p className="max-w-xs text-sm text-[var(--text-muted)]">{description}</p>
      )}
      {action}
    </div>
  )
}

/**
 * El pie de una lista que no cabe entera. **Cuántas hay, y cómo ver más.**
 *
 * Existe porque tres pantallas cortaban en silencio: la carta enseñaba 50 de
 * 693 productos sin nada que lo sugiriera. Y cortar en silencio es el peor
 * fallo que puede tener una lista — quien la mira no sabe que le falta algo, así
 * que no busca. La cocina ya avisaba de lo que no cabía; esto es lo mismo para
 * las listas que sí se pueden seguir.
 *
 * Es presentacional a propósito: no sabe nada de consultas ni de páginas. Así lo
 * usan igual el panel y la super administración, que traen sus datos de formas
 * distintas.
 */
export function ListFooter({
  shown,
  total,
  onMore,
  loading = false,
  /** «productos», «clientes», «negocios». */
  noun,
}: {
  shown: number
  total: number
  onMore: () => void
  loading?: boolean
  noun: string
}) {
  if (total <= shown) return null

  return (
    <div className="flex flex-col items-center gap-2 py-4">
      <p className="text-sm text-[var(--text-muted)]">
        Se ven {shown} de {total} {noun}
      </p>

      <button
        type="button"
        onClick={onMore}
        disabled={loading}
        className="min-h-touch rounded-[var(--radius-md)] bg-[var(--surface-raised)] px-6 font-medium text-[var(--text-strong)] shadow-[var(--shadow-card)] disabled:opacity-60"
      >
        {loading ? 'Cargando…' : `Ver ${Math.min(shown, total - shown)} más`}
      </button>
    </div>
  )
}

export function Spinner({ label = 'Cargando…' }: { label?: string }) {
  return (
    <p role="status" className="px-6 py-12 text-center text-sm text-[var(--text-muted)]">
      {label}
    </p>
  )
}
