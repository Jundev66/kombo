import { useEffect, type ReactNode } from 'react'

/**
 * Una hoja que sube desde abajo.
 *
 * Abajo y no en el centro: en una pantalla táctil de mostrador, lo que hay que
 * tocar tiene que quedar donde llega el pulgar. Un diálogo centrado obliga a
 * levantar la mano de la máquina.
 */
export function Sheet({
  title,
  onClose,
  children,
  footer,
}: {
  title: string
  onClose: () => void
  children: ReactNode
  footer?: ReactNode
}) {
  // Escape cierra. Es lo que todo el mundo intenta primero.
  useEffect(() => {
    function onKey(event: KeyboardEvent): void {
      if (event.key === 'Escape') onClose()
    }

    window.addEventListener('keydown', onKey)

    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  return (
    <div className="fixed inset-0 z-50 flex flex-col justify-end sm:justify-center sm:items-center">
      {/* El fondo cierra, pero no es un botón: pulsar Tab no tiene que
          llevarte a «el fondo». */}
      <div
        className="absolute inset-0 bg-ink-900/50"
        onClick={onClose}
        aria-hidden="true"
      />

      <div
        role="dialog"
        aria-modal="true"
        aria-label={title}
        className="relative flex max-h-[90dvh] w-full flex-col rounded-t-[var(--radius-lg)] bg-[var(--surface-raised)] sm:max-w-lg sm:rounded-[var(--radius-lg)]"
      >
        <header className="flex shrink-0 items-center justify-between border-b border-[var(--surface-border)] px-5 py-4">
          <h2 className="text-lg font-semibold text-[var(--text-strong)]">{title}</h2>

          <button
            type="button"
            onClick={onClose}
            aria-label="Cerrar"
            className="min-h-11 px-3 text-2xl leading-none text-[var(--text-muted)]"
          >
            ×
          </button>
        </header>

        <div className="flex-1 overflow-y-auto px-5 py-4">{children}</div>

        {footer !== undefined && (
          <footer className="shrink-0 border-t border-[var(--surface-border)] p-4">{footer}</footer>
        )}
      </div>
    </div>
  )
}
