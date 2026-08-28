import type { InputHTMLAttributes } from 'react'
import { cn } from '../lib/cn'

interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  invalid?: boolean
}

export function Input({ invalid = false, className, ...props }: InputProps) {
  return (
    <input
      aria-invalid={invalid || undefined}
      className={cn(
        'h-11 w-full rounded-[var(--radius-md)] border bg-[var(--surface-raised)] px-3',
        'text-[var(--text-strong)] placeholder:text-[var(--text-muted)]',
        invalid ? 'border-bad-500' : 'border-[var(--surface-border)]',
        className,
      )}
      {...props}
    />
  )
}
