import { Badge, Button, Card, EmptyState, Field, Input, Money, Spinner, parseAmount, Page} from '@kombo/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { delivery } from '../api/delivery'

/**
 * Las zonas de reparto: un barrio, su tarifa y cuánto se tarda.
 *
 * Los minutos no son un adorno. Decirle al cliente «como media hora» ANTES de
 * que pida evita la mitad de las llamadas preguntando por el pedido, que es lo
 * que más tiempo le quita a quien está cocinando.
 */
export function ZonesScreen() {
  const queryClient = useQueryClient()

  const [nombre, setNombre] = useState('')
  const [tarifa, setTarifa] = useState('')
  const [minutos, setMinutos] = useState('')
  const [error, setError] = useState<string | null>(null)

  const zones = useQuery({ queryKey: ['zones'], queryFn: () => delivery.zones(true) })

  const invalidar = (): void => {
    void queryClient.invalidateQueries({ queryKey: ['zones'] })
  }

  const crear = useMutation({
    mutationFn: (body: { name: string; fee_cents: number; estimated_minutes: number | null }) =>
      delivery.create(body),
    onSuccess: () => {
      setNombre('')
      setTarifa('')
      setMinutos('')
      invalidar()
    },
  })

  const cambiar = useMutation({
    mutationFn: ({ id, body }: { id: string; body: Record<string, unknown> }) =>
      delivery.update(id, body),
    onSuccess: invalidar,
  })

  function onSubmit(event: FormEvent): void {
    event.preventDefault()

    const cents = parseAmount(tarifa)

    if (nombre.trim() === '') {
      setError('¿Cómo se llama la zona?')
      return
    }

    if (cents === null || cents < 0) {
      setError('¿Cuánto se cobra por llevar hasta ahí?')
      return
    }

    setError(null)
    crear.mutate({
      name: nombre.trim(),
      fee_cents: cents,
      estimated_minutes: minutos === '' ? null : Number(minutos),
    })
  }

  return (
    <Page ancho="lectura" className="flex flex-col gap-4">
      <h1 className="text-xl font-bold text-[var(--text-strong)]">Zonas de reparto</h1>

      <form onSubmit={onSubmit} className="flex flex-col gap-3 sm:flex-row sm:items-end">
        <div className="flex-1">
          <Field label="Zona" error={error ?? undefined}>
            {({ id, invalid }) => (
              <Input
                id={id}
                value={nombre}
                invalid={invalid}
                placeholder="Los Palos Grandes"
                onChange={(e) => setNombre(e.target.value)}
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
                value={tarifa}
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
                value={minutos}
                placeholder="30"
                onChange={(e) => setMinutos(e.target.value)}
              />
            )}
          </Field>
        </div>

        <Button type="submit" disabled={crear.isPending}>
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
                  onClick={() => cambiar.mutate({ id: zone.id, body: { is_active: false } })}
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
                    onClick={() => cambiar.mutate({ id: zone.id, body: { is_active: true } })}
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
