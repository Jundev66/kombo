import { ApiError } from '@kombo/api-client'
import { Button, Card, Input, Spinner, Toggle, Page} from '@kombo/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { hours, type BusinessDay } from '../api/hours'

/**
 * El horario.
 *
 * Esto decide si el portal acepta pedidos: **un día sin configurar está
 * cerrado**, que es el fallo seguro —un pedido de un día que nadie configuró
 * llega a una cocina apagada—. Por eso los siete días vienen siempre y se
 * guardan juntos.
 *
 * Y admite turnos que **cruzan la medianoche**: «de 6 de la tarde a 2 de la
 * madrugada» es el horario normal de media comida rápida, y el servidor lo
 * entiende sin que aquí haya que explicarlo.
 */
export function HoursScreen() {
  const queryClient = useQueryClient()

  const consulta = useQuery({ queryKey: ['hours'], queryFn: hours.list })
  const [dias, setDias] = useState<BusinessDay[]>([])
  const [error, setError] = useState<string | null>(null)
  const [guardado, setGuardado] = useState(false)

  useEffect(() => {
    if (consulta.data !== undefined) setDias(consulta.data)
  }, [consulta.data])

  const guardar = useMutation({
    mutationFn: () =>
      hours.save(
        dias.map((dia) => ({
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

  function cambiar(weekday: number, cambios: Partial<BusinessDay>): void {
    setGuardado(false)
    setDias((actuales) =>
      actuales.map((dia) => (dia.weekday === weekday ? { ...dia, ...cambios } : dia)),
    )
  }

  if (consulta.isLoading) return <Spinner />

  return (
    <Page ancho="lectura" className="flex flex-col gap-4">
      <h1 className="text-xl font-bold text-[var(--text-strong)]">Horario</h1>

      <p className="text-sm text-[var(--text-muted)]">
        Fuera de este horario el portal no toma pedidos y el bot avisa de que estás cerrado. Si
        cierras de madrugada, pon la hora igual: «de 18:00 a 02:00» se entiende.
      </p>

      <Card className="flex flex-col divide-y divide-[var(--surface-border)]">
        {dias.map((dia) => (
          <div key={dia.weekday} className="flex flex-wrap items-center gap-3 p-4">
            <span className="w-24 font-medium capitalize text-[var(--text-strong)]">
              {dia.label}
            </span>

            <div className="w-40">
              <Toggle
                checked={!dia.isClosed}
                label={dia.isClosed ? 'Cerrado' : 'Abierto'}
                onChange={(abierto) => cambiar(dia.weekday, { isClosed: !abierto })}
              />
            </div>

            {!dia.isClosed && (
              /*
               * Las dos horas ocupan la fila entera en un teléfono estrecho.
               *
               * Con `w-32` fijo se salían de la pantalla a 320 px: dos campos
               * de 128, el «a» y los espacios no caben en los 256 que quedan
               * tras los rellenos. Y un desbordamiento horizontal en una
               * pantalla de configuración es de los que nadie reporta — se
               * arrastra el dedo, se ve mal, y se cierra.
               */
              <div className="flex w-full items-center gap-2 sm:w-auto">
                <Input
                  type="time"
                  aria-label={`Abre el ${dia.label}`}
                  value={dia.opensAt ?? '08:00'}
                  onChange={(e) => cambiar(dia.weekday, { opensAt: e.target.value })}
                  className="w-full min-w-0 sm:w-32"
                />

                <span className="text-[var(--text-muted)]">a</span>

                <Input
                  type="time"
                  aria-label={`Cierra el ${dia.label}`}
                  value={dia.closesAt ?? '20:00'}
                  onChange={(e) => cambiar(dia.weekday, { closesAt: e.target.value })}
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
        <Button disabled={guardar.isPending} onClick={() => guardar.mutate()}>
          {guardar.isPending ? 'Guardando…' : 'Guardar el horario'}
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
