import { Button, Card, Field, Input, Money, Spinner } from '@kombo/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { catalog } from '../api/catalog'

/**
 * La tasa del día.
 *
 * Es un gesto de diez segundos, normalmente antes de abrir, y condiciona todo
 * lo que se cobra ese día. Por eso es la pantalla más simple del sistema: un
 * campo, un botón, y la comprobación de que el número quedó bien.
 */
export function RateScreen() {
  const queryClient = useQueryClient()
  const [valor, setValor] = useState('')
  const [error, setError] = useState<string | null>(null)

  const rate = useQuery({ queryKey: ['rate'], queryFn: catalog.rate })

  const guardar = useMutation({
    mutationFn: () => {
      const parsed = Number((valor || '').replace(',', '.'))

      if (!Number.isFinite(parsed) || parsed <= 0) {
        // Una tasa de cero convertiría todos los precios en cero, y el primero
        // en enterarse sería el cliente.
        throw new Error('Escribe una tasa mayor que cero, como 36,50.')
      }

      return catalog.setRate(parsed)
    },
    onSuccess: () => {
      setValor('')
      setError(null)
      void queryClient.invalidateQueries({ queryKey: ['rate'] })
    },
    onError: (caught: unknown) => {
      setError(caught instanceof Error ? caught.message : 'No se pudo guardar.')
    },
  })

  function onSubmit(event: FormEvent): void {
    event.preventDefault()
    guardar.mutate()
  }

  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-xl font-bold text-[var(--text-strong)]">Tasa del día</h1>

      {rate.isLoading && <Spinner />}

      {rate.data != null && (
        <Card className="p-4">
          <p className="text-sm text-[var(--text-muted)]">Ahora mismo</p>
          <p className="text-money font-semibold tabular text-[var(--text-strong)]">
            Bs {rate.data.rate.toLocaleString('es-VE')} por dólar
          </p>

          {!rate.data.isToday && (
            // Avisar de que es vieja importa: con una tasa de la semana pasada
            // se cobra de menos todos los días sin que nadie lo note.
            <p role="alert" className="mt-2 text-sm text-warn-700">
              Esta tasa es del {rate.data.effectiveDate}. Cárgala de nuevo.
            </p>
          )}
        </Card>
      )}

      {rate.data === null && !rate.isLoading && (
        <p role="alert" className="rounded-[var(--radius-md)] bg-warn-50 p-3 text-sm text-warn-700">
          Todavía no has cargado ninguna tasa. Sin ella no se puede cobrar en bolívares.
        </p>
      )}

      <form onSubmit={onSubmit} className="flex flex-col gap-3">
        <Field label="Bolívares por dólar" required error={error ?? undefined}>
          {({ id, invalid }) => (
            <Input
              id={id}
              inputMode="decimal"
              placeholder="36,50"
              value={valor}
              invalid={invalid}
              onChange={(e) => setValor(e.target.value)}
            />
          )}
        </Field>

        <Button type="submit" size="touch" block disabled={guardar.isPending}>
          {guardar.isPending ? 'Guardando…' : 'Guardar la tasa'}
        </Button>
      </form>

      {/* Comprobar el resultado con un importe real evita el cero de más: un
          «100 $ son Bs 3.650,00» se revisa de un vistazo; un «36,5» no. */}
      {rate.data != null && (
        <Card className="p-4">
          <p className="mb-1 text-sm text-[var(--text-muted)]">Para comprobar</p>
          <Money cents={10000} rate={rate.data.rate} scale="sm" />
        </Card>
      )}
    </div>
  )
}
