import type { ButtonHTMLAttributes } from 'react'
import { cn } from '../lib/cn'

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger'
type Size = 'sm' | 'md' | 'touch'

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant
  size?: Size
  /** Ocupa todo el ancho. En móvil casi siempre es lo que quieres. */
  block?: boolean
}

/**
 * El botón.
 *
 * Expone `variant` y `size`, **no** un `className` que reemplace su fondo.
 * `className` sirve para posicionar (márgenes, ancho), no para redefinir
 * apariencia: si hace falta una apariencia nueva, es una VARIANTE nueva aquí,
 * no una excepción en una pantalla. Si no, en seis meses hay catorce botones
 * primarios distintos y ninguno es el correcto.
 *
 * `primary` es naranja y no verde a propósito: el verde está reservado para
 * estado («esto salió bien»), y cobrar no es un estado.
 */
const VARIANTS: Record<Variant, string> = {
  primary: 'bg-accent-500 text-white hover:bg-accent-600 active:bg-accent-700',
  secondary:
    'bg-[var(--surface-raised)] text-[var(--text-strong)] border border-[var(--surface-border)] hover:bg-[var(--surface-sunken)]',
  ghost: 'text-[var(--text-default)] hover:bg-[var(--surface-sunken)]',
  danger: 'bg-bad-500 text-white hover:bg-bad-700',
}

const SIZES: Record<Size, string> = {
  sm: 'h-9 px-3 text-sm',
  md: 'h-11 px-4 text-base',
  // 56 px: el mínimo con el que se acierta con el pulgar, de pie y con prisa.
  touch: 'min-h-touch px-6 text-lg',
}

export function Button({
  variant = 'primary',
  size = 'md',
  block = false,
  className,
  type = 'button',
  ...props
}: ButtonProps) {
  return (
    <button
      type={type}
      className={cn(
        'inline-flex items-center justify-center gap-2 rounded-[var(--radius-md)] font-medium',
        'transition-colors disabled:cursor-not-allowed disabled:opacity-50',
        VARIANTS[variant],
        SIZES[size],
        block && 'w-full',
        className,
      )}
      {...props}
    />
  )
}
