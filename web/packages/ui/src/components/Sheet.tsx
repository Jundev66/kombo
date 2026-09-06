import { useEffect, type ReactNode } from 'react'

/**
 * A sheet that rises from the bottom.
 *
 * Bottom rather than centre: on a counter touchscreen, what has to be tapped
 * belongs where the thumb reaches. A centred dialog means lifting your hand off
 * the machine.
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
  // Escape closes. It is what everybody tries first.
  useEffect(() => {
    function onKey(event: KeyboardEvent): void {
      if (event.key === 'Escape') onClose()
    }

    window.addEventListener('keydown', onKey)

    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  return (
    <div className="fixed inset-0 z-50 flex flex-col justify-end sm:justify-center sm:items-center">
      {/* The backdrop closes it but is not a button: Tab must not take you to
          "the backdrop". */}
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
