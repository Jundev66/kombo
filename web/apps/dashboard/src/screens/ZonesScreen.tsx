import { Badge, Button, Card, EmptyState, Field, Input, Money, Spinner, parseAmount, Page} from '@kombo/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { delivery } from '../api/delivery'

/**
 * The delivery zones: a neighbourhood, its fee and how long it takes.
 *
 * The minutes are not decoration. Telling the customer "about half an hour"
 * BEFORE they order heads off half the calls asking after the order, which is
 * what takes most time from whoever is cooking.
 */
export function ZonesScreen() {
  const queryClient = useQueryClient()

  const [name, setName] = useState('')
  const [fee, setTarifa] = useState('')
  const [minutes, setMinutos] = useState('')
  const [error, setError] = useState<string | null>(null)

  const zones = useQuery({ queryKey: ['zones'], queryFn: () => delivery.zones(true) })

  const invalidate = (): void => {
    void queryClient.invalidateQueries({ queryKey: ['zones'] })
  }

  const create = useMutation({
    mutationFn: (body: { name: string; fee_cents: number; estimated_minutes: number | null }) =>
      delivery.create(body),
    onSuccess: () => {
      setName('')
      setTarifa('')
      setMinutos('')
      invalidate()
    },
  })

  const change = useMutation({
    mutationFn: ({ id, body }: { id: string; body: Record<string, unknown> }) =>
      delivery.update(id, body),
    onSuccess: invalidate,
  })

  function onSubmit(event: FormEvent): void {
    event.preventDefault()

    const cents = parseAmount(fee)

    if (name.trim() === '') {
      setError('¿Cómo se llama la zona?')
      return
    }

    if (cents === null || cents < 0) {
      setError('¿Cuánto se cobra por llevar hasta ahí?')
      return
    }

    setError(null)
    create.mutate({
      name: name.trim(),
      fee_cents: cents,
      estimated_minutes: minutes === '' ? null : Number(minutes),
    })
  }

  return (
    <Page width="reading" className="flex flex-col gap-4">
      <h1 className="text-xl font-bold text-[var(--text-strong)]">Zonas de reparto</h1>

      <form onSubmit={onSubmit} className="flex flex-col gap-3 sm:flex-row sm:items-end">
        <div className="flex-1">
          <Field label="Zona" error={error ?? undefined}>
            {({ id, invalid }) => (
              <Input
                id={id}
                value={name}
                invalid={invalid}
                placeholder="Los Palos Grandes"
                onChange={(e) => setName(e.target.value)}
              />
            )}
          </Field>
        </div>

        <div className="w-32">
          <Field label="Se cobra">
            {({ id }) => (
              <Input
                id={id}
                inputMode="decimal"
                value={fee}
                placeholder="2,00"
                onChange={(e) => setTarifa(e.target.value)}
              />
            )}
          </Field>
        </div>

        <div className="w-32">
          <Field label="Minutos" hint="Lo que se le promete.">
            {({ id }) => (
              <Input
                id={id}
                inputMode="numeric"
                value={minutes}
                placeholder="30"
                onChange={(e) => setMinutos(e.target.value)}
              />
            )}
          </Field>
        </div>

        <Button type="submit" disabled={create.isPending}>
          Añadir
        </Button>
      </form>

      {zones.isLoading && <Spinner />}

      {zones.data?.length === 0 && (
        <EmptyState
          title="Todavía no repartes a ningún sitio"
          description="Añade los barrios a los que llevas, con lo que cobras por cada uno. El cliente elige el suyo de la lista."
        />
      )}

      <ul className="flex flex-col gap-2">
        {zones.data?.map((zone) => (
          <li key={zone.id}>
            <Card className="flex min-h-touch flex-wrap items-center gap-3 p-3">
              <span className="flex-1 font-medium text-[var(--text-strong)]">{zone.name}</span>

              {zone.estimatedMinutes != null && <Badge>{zone.estimatedMinutes} min</Badge>}

              <Money cents={zone.feeCents} />

              {zone.isActive ? (
                <Button
                  variant="ghost"
                  size="sm"
                  aria-label={`Dejar de repartir a ${zone.name}`}
                  onClick={() => change.mutate({ id: zone.id, body: { is_active: false } })}
                >
                  Dejar de repartir
                </Button>
              ) : (
                <>
                  <Badge tone="warn">No se reparte</Badge>
                  <Button
                    variant="ghost"
                    size="sm"
                    aria-label={`Volver a repartir a ${zone.name}`}
                    onClick={() => change.mutate({ id: zone.id, body: { is_active: true } })}
                  >
                    Volver a repartir
                  </Button>
                </>
              )}
            </Card>
          </li>
        ))}
      </ul>
    </Page>
  )
}
