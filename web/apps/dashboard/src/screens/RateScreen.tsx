import { Button, Card, Field, Input, Money, Spinner, Page} from '@kombo/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { catalog } from '../api/catalog'

/**
 * The rate of the day.
 *
 * A ten-second gesture, usually before opening, that governs everything charged
 * that day. Hence the simplest screen in the system: one field, one button, and
 * a check that the number came out right.
 */
export function RateScreen() {
  const queryClient = useQueryClient()
  const [value, setValue] = useState('')
  const [error, setError] = useState<string | null>(null)

  const rate = useQuery({ queryKey: ['rate'], queryFn: catalog.rate })

  const save = useMutation({
    mutationFn: () => {
      const parsed = Number((value || '').replace(',', '.'))

      if (!Number.isFinite(parsed) || parsed <= 0) {
        // A rate of zero would turn every price into zero, and the first to find
        // out would be the customer.
        throw new Error('Escribe una tasa mayor que cero, como 36,50.')
      }

      return catalog.setRate(parsed)
    },
    onSuccess: () => {
      setValue('')
      setError(null)
      void queryClient.invalidateQueries({ queryKey: ['rate'] })
    },
    onError: (caught: unknown) => {
      setError(caught instanceof Error ? caught.message : 'No se pudo guardar.')
    },
  })

  function onSubmit(event: FormEvent): void {
    event.preventDefault()
    save.mutate()
  }

  return (
    <Page width="reading" className="flex flex-col gap-4">
      <h1 className="text-xl font-bold text-[var(--text-strong)]">Tasa del día</h1>

      {rate.isLoading && <Spinner />}

      {rate.data != null && (
        <Card className="p-4">
          <p className="text-sm text-[var(--text-muted)]">Ahora mismo</p>
          <p className="text-money font-semibold tabular text-[var(--text-strong)]">
            Bs {rate.data.rate.toLocaleString('es-VE')} por dólar
          </p>

          {!rate.data.isToday && (
            // Warning that it is stale matters: with last week's rate the tenant
            // undercharges every day without anyone noticing.
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
              value={value}
              invalid={invalid}
              onChange={(e) => setValue(e.target.value)}
            />
          )}
        </Field>

        <Button type="submit" size="touch" block disabled={save.isPending}>
          {save.isPending ? 'Guardando…' : 'Guardar la tasa'}
        </Button>
      </form>

      {/* Checking the result against a real amount catches the extra zero: "100 $
          is Bs 3.650,00" is verified at a glance; "36.5" is not. */}
      {rate.data != null && (
        <Card className="p-4">
          <p className="mb-1 text-sm text-[var(--text-muted)]">Para comprobar</p>
          <Money cents={10000} rate={rate.data.rate} scale="sm" />
        </Card>
      )}
    </Page>
  )
}
