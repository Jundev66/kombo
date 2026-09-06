import type { SelectHTMLAttributes, TextareaHTMLAttributes } from 'react'
import { cn } from '../lib/cn'

const BASE =
  'w-full rounded-[var(--radius-md)] border bg-[var(--surface-raised)] px-3 text-[var(--text-strong)]'

export function Select({
  invalid = false,
  className,
  ...props
}: SelectHTMLAttributes<HTMLSelectElement> & { invalid?: boolean }) {
  return (
    <select
      aria-invalid={invalid || undefined}
      className={cn(BASE, 'h-11', invalid ? 'border-bad-500' : 'border-[var(--surface-border)]', className)}
      {...props}
    />
  )
}

export function Textarea({
  invalid = false,
  className,
  ...props
}: TextareaHTMLAttributes<HTMLTextAreaElement> & { invalid?: boolean }) {
  return (
    <textarea
      aria-invalid={invalid || undefined}
      className={cn(
        BASE,
        'min-h-24 py-2',
        invalid ? 'border-bad-500' : 'border-[var(--surface-border)]',
        className,
      )}
      {...props}
    />
  )
}

/**
 * A switch.
 *
 * A real `checkbox` underneath rather than a `div` with `onClick`: that way the
 * keyboard reaches it, a screen reader announces it, and `getByRole('switch')`
 * finds it in tests without inventing selectors.
 */
export function Toggle({
  checked,
  onChange,
  label,
}: {
  checked: boolean
  onChange: (value: boolean) => void
  label: string
}) {
  return (
    <label className="flex min-h-11 cursor-pointer items-center justify-between gap-3">
      <span className="text-sm font-medium text-[var(--text-strong)]">{label}</span>

      <input
        type="checkbox"
        role="switch"
        checked={checked}
        onChange={(e) => onChange(e.target.checked)}
        className="h-6 w-11 shrink-0 appearance-none rounded-full bg-[var(--surface-border)] transition-colors checked:bg-brand-500 relative before:absolute before:top-0.5 before:left-0.5 before:h-5 before:w-5 before:rounded-full before:bg-white before:transition-transform checked:before:translate-x-5"
      />
    </label>
  )
}
