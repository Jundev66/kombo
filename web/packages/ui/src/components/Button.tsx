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
  // 56 px: the minimum you can hit with a thumb, standing up and in a hurry.
  touch: 'min-h-touch px-6 text-lg',
}

/**
 * A button's classes, so a LINK can look the same.
 *
 * Needed because "go to another screen" is a link and "do something" is a
 * button, and a `<Link>` inside a `<button>` breaks both: it does not navigate,
 * and it nests two interactive controls that keyboards and screen readers
 * cannot interpret.
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
 * The button.
 *
 * It exposes `variant` and `size`, NOT a `className` that replaces its
 * background. `className` is for positioning, not for redefining appearance: a
 * new appearance is a new VARIANT here, or in six months there are fourteen
 * different primary buttons and none of them is the right one.
 *
 * `primary` is orange rather than green on purpose: green is reserved for
 * status ("this went well"), and taking payment is not a status.
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
