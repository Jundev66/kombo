import { ApiError } from '@kombo/api-client'
import { Button, Card, Input, Spinner, Toggle, Page} from '@kombo/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { hours, type BusinessDay } from '../api/hours'

/**
 * The opening hours.
 *
 * These decide whether the portal takes orders: an unconfigured day is closed,
 * which is the safe failure. Hence all seven days always come back and are
 * saved together.
 *
 * Shifts crossing midnight are allowed: "six in the evening to two in the
 * morning" is normal for half of fast food, and the server understands it
 * without this screen explaining anything.
 */
export function HoursScreen() {
  const queryClient = useQueryClient()

  const query = useQuery({ queryKey: ['hours'], queryFn: hours.list })
  const [days, setDias] = useState<BusinessDay[]>([])
  const [error, setError] = useState<string | null>(null)
  const [guardado, setGuardado] = useState(false)

  useEffect(() => {
    if (query.data !== undefined) setDias(query.data)
  }, [query.data])

  const save = useMutation({
    mutationFn: () =>
      hours.save(
        days.map((dia) => ({
          weekday: dia.weekday,
          opens_at: dia.isClosed ? null : (dia.opensAt ?? '08:00'),
          closes_at: dia.isClosed ? null : (dia.closesAt ?? '20:00'),
          is_closed: dia.isClosed,
        })),
      ),
    onSuccess: () => {
      setError(null)
      setGuardado(true)
      void queryClient.invalidateQueries({ queryKey: ['hours'] })
    },
    onError: (failure: unknown) =>
      setError(failure instanceof ApiError ? failure.message : 'No se pudo guardar.'),
  })

  function change(weekday: number, changes: Partial<BusinessDay>): void {
    setGuardado(false)
    setDias((actuales) =>
      actuales.map((dia) => (dia.weekday === weekday ? { ...dia, ...changes } : dia)),
    )
  }

  if (query.isLoading) return <Spinner />

  return (
    <Page width="reading" className="flex flex-col gap-4">
      <h1 className="text-xl font-bold text-[var(--text-strong)]">Horario</h1>

      <p className="text-sm text-[var(--text-muted)]">
        Fuera de este horario el portal no toma pedidos y el bot avisa de que estás cerrado. Si
        cierras de madrugada, pon la hora igual: «de 18:00 a 02:00» se entiende.
      </p>

      <Card className="flex flex-col divide-y divide-[var(--surface-border)]">
        {days.map((dia) => (
          <div key={dia.weekday} className="flex flex-wrap items-center gap-3 p-4">
            <span className="w-24 font-medium capitalize text-[var(--text-strong)]">
              {dia.label}
            </span>

            <div className="w-40">
              <Toggle
                checked={!dia.isClosed}
                label={dia.isClosed ? 'Cerrado' : 'Abierto'}
                onChange={(openNow) => change(dia.weekday, { isClosed: !openNow })}
              />
            </div>

            {!dia.isClosed && (
              /*
               * The two times take a whole row on a narrow phone.
               *
               * With a fixed `w-32` they overflowed at 320 px. And a horizontal
               * overflow on a settings screen is the kind nobody reports —
               * people drag, see it looks wrong, and close it.
               */
              <div className="flex w-full items-center gap-2 sm:w-auto">
                <Input
                  type="time"
                  aria-label={`Abre el ${dia.label}`}
                  value={dia.opensAt ?? '08:00'}
                  onChange={(e) => change(dia.weekday, { opensAt: e.target.value })}
                  className="w-full min-w-0 sm:w-32"
                />

                <span className="text-[var(--text-muted)]">a</span>

                <Input
                  type="time"
                  aria-label={`Cierra el ${dia.label}`}
                  value={dia.closesAt ?? '20:00'}
                  onChange={(e) => change(dia.weekday, { closesAt: e.target.value })}
                  className="w-full min-w-0 sm:w-32"
                />
              </div>
            )}
          </div>
        ))}
      </Card>

      {error != null && (
        <p role="alert" className="text-sm font-medium text-bad-500">
          {error}
        </p>
      )}

      <div className="flex items-center gap-3">
        <Button disabled={save.isPending} onClick={() => save.mutate()}>
          {save.isPending ? 'Guardando…' : 'Guardar el horario'}
        </Button>

        {guardado && (
          <span role="status" className="text-sm font-medium text-ok-700">
            Guardado
          </span>
        )}
      </div>
    </Page>
  )
}
