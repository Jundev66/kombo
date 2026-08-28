import type { ButtonHTMLAttributes } from 'react'
import { cn } from '../lib/cn'

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger'
export type ButtonSize = 'sm' | 'md' | 'touch'

const VARIANTS: Record<ButtonVariant, string> = {
  primary: 'bg-accent-500 text-white hover:bg-accent-600 active:bg-accent-700',
  secondary:
    'bg-[var(--surface-raised)] text-[var(--text-strong)] border border-[var(--surface-border)] hover:bg-[var(--surface-sunken)]',
  ghost: 'text-[var(--text-default)] hover:bg-[var(--surface-sunken)]',
  danger: 'bg-bad-500 text-white hover:bg-bad-700',
}

const SIZES: Record<ButtonSize, string> = {
  sm: 'h-9 px-3 text-sm',
  md: 'h-11 px-4 text-base',
  // 56 px: el mínimo con el que se acierta con el pulgar, de pie y con prisa.
  touch: 'min-h-touch px-6 text-lg',
}

/**
 * Las clases de un botón, para que un ENLACE pueda verse igual.
 *
 * Hace falta porque «ir a otra pantalla» es un enlace y «hacer algo» es un
 * botón, y meter un `<Link>` dentro de un `<button>` rompe las dos cosas: no
 * navega, y anida dos controles interactivos que el teclado y los lectores de
 * pantalla no saben interpretar.
 */
export function buttonClasses(
  variant: ButtonVariant = 'primary',
  size: ButtonSize = 'md',
  block = false,
): string {
  return cn(
    'inline-flex items-center justify-center gap-2 rounded-[var(--radius-md)] font-medium',
    'transition-colors disabled:cursor-not-allowed disabled:opacity-50',
    VARIANTS[variant],
    SIZES[size],
    block && 'w-full',
  )
}

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant
  size?: ButtonSize
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
export function Button({
  variant = 'primary',
  size = 'md',
  block = false,
  className,
  type = 'button',
  ...props
}: ButtonProps) {
  return <button type={type} className={cn(buttonClasses(variant, size, block), className)} {...props} />
}
