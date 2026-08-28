import { useId, type ReactNode } from 'react'
import { cn } from '../lib/cn'

interface FieldProps {
  label: string
  // `| undefined` explícito: con `exactOptionalPropertyTypes`, «sin error» y
  // «error indefinido» son cosas distintas, y quien llama suele tener un
  // `string | null` del que sale un `undefined`.
  hint?: string | undefined
  error?: string | undefined
  required?: boolean
  /** Recibe el id ya generado y si el campo está en error. */
  children: (props: { id: string; invalid: boolean }) => ReactNode
}

/**
 * Un campo con su etiqueta de verdad.
 *
 * Usa render-prop para que el `<label>` apunte al control con `htmlFor`. No es
 * cosmética: sin esa asociación, tocar la etiqueta en un teléfono no enfoca el
 * campo, un lector de pantalla no sabe qué se está pidiendo, y las pruebas no
 * pueden encontrarlo por su nombre accesible.
 *
 * El asterisco de obligatorio va `aria-hidden`: forma parte del texto visible
 * pero no del nombre accesible, para que `getByRole('textbox', {name: 'Correo'})`
 * lo encuentre sin el asterisco.
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
