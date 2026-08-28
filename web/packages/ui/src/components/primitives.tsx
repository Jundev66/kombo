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

export function Spinner({ label = 'Cargando…' }: { label?: string }) {
  return (
    <p role="status" className="px-6 py-12 text-center text-sm text-[var(--text-muted)]">
      {label}
    </p>
  )
}
