import { useId, type ReactNode } from 'react'
import { cn } from '../lib/cn'

interface FieldProps {
  label: string
  // Explicit `| undefined`: with `exactOptionalPropertyTypes`, "no error" and
  // "undefined error" are different things, and callers usually hold a
  // `string | null` that yields an `undefined`.
  hint?: string | undefined
  error?: string | undefined
  required?: boolean
  /** Receives the generated id and whether the field is in error. */
  children: (props: { id: string; invalid: boolean }) => ReactNode
}

/**
 * A field with a real label.
 *
 * A render prop, so the `<label>` points at the control with `htmlFor`. Not
 * cosmetic: without that association, tapping the label on a phone does not
 * focus the field, a screen reader does not know what is being asked, and tests
 * cannot find it by its accessible name.
 *
 * The required asterisk is `aria-hidden`: part of the visible text but not of
 * the accessible name, so `getByRole('textbox', {name: 'Correo'})` finds it.
 */
export function Field({ label, hint, error, required = false, children }: FieldProps) {
  const id = useId()
  const invalid = error !== undefined && error !== ''

  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={id} className="text-sm font-medium text-[var(--text-strong)]">
        {label}
        {required && (
          <span aria-hidden="true" className="ml-0.5 text-bad-500">
            *
          </span>
        )}
      </label>

      {children({ id, invalid })}

      {hint !== undefined && !invalid && (
        <p className="text-xs text-[var(--text-muted)]">{hint}</p>
      )}

      {invalid && (
        <p role="alert" className={cn('text-xs font-medium text-bad-500')}>
          {error}
        </p>
      )}
    </div>
  )
}
