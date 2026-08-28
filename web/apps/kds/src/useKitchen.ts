import { useCallback, useEffect, useRef, useState } from 'react'
import { kitchen, type Ticket } from './api'

/**
 * Las comandas de la pantalla.
 *
 * **Por sondeo cada 5 segundos, no por websocket.** Un websocket implica
 * conexiones abiertas, reconexión, latidos y un estado más que puede quedarse
 * pegado sin que nadie lo note. Una tablet de cocina con wifi malo se recupera
 * sola de un sondeo; de un socket caído, no. Y cinco segundos de retraso en
 * una cocina no los nota nadie.
 */
export function useKitchen() {
  const [tickets, setTickets] = useState<Ticket[]>([])
  const [staleMinutes, setStaleMinutes] = useState(15)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)

  // Cuándo se recibió la última respuesta. El cronómetro le suma los segundos
  // transcurridos desde entonces, así que no depende del reloj de la tablet.
  const fetchedAt = useRef(Date.now())
  const [, tick] = useState(0)

  const load = useCallback(async () => {
    try {
      const { data, meta } = await kitchen.tickets()

      setTickets(data)
      setStaleMinutes(meta.staleMinutes)
      setError(null)
      fetchedAt.current = Date.now()
    } catch {
      /*
       * Si falla, NO se borra la pantalla.
       *
       * Una cocina sin conexión sigue teniendo esos pedidos en la plancha.
       * Vaciar la lista porque el wifi parpadeó sería hacer perder comandas
       * que están físicamente ahí.
       */
      setError('Sin conexión')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void load()

    const polling = setInterval(() => void load(), 5_000)
    // Un tick local cada segundo, sólo para que el cronómetro no salte de
    // cinco en cinco. No consulta nada.
    const clock = setInterval(() => tick((n) => n + 1), 1_000)

    return () => {
      clearInterval(polling)
      clearInterval(clock)
    }
  }, [load])

  /**
   * Avanza una comanda, pintando el cambio ANTES de la respuesta.
   *
   * Tocar «Listo» y que no pase nada medio segundo significa tocarlo otra vez,
   * y en una cocina eso es lo que ocurre. Si el servidor dice que no, la
   * siguiente consulta lo devuelve a su sitio.
   */
  const advance = useCallback(
    async (ticket: Ticket) => {
      if (ticket.nextStatus === null) return

      const next = ticket.nextStatus

      setTickets((prev) =>
        next === 'served'
          ? prev.filter((t) => t.id !== ticket.id)
          : prev.map((t) => (t.id === ticket.id ? { ...t, status: next as Ticket['status'] } : t)),
      )

      try {
        await kitchen.advance(ticket.id, next)
      } finally {
        void load()
      }
    },
    [load],
  )

  /** Cuánto lleva esperando, ahora mismo. */
  const waitedSeconds = useCallback(
    (ticket: Ticket): number =>
      ticket.waitingSeconds + Math.floor((Date.now() - fetchedAt.current) / 1000),
    [],
  )

  /**
   * ¿Va tarde?
   *
   * Se usa el tiempo del PRODUCTO si se conoce —una parrilla no es una arepa—
   * y el umbral del negocio como red de seguridad cuando no.
   */
  const isLate = useCallback(
    (ticket: Ticket): boolean =>
      waitedSeconds(ticket) >= (ticket.prepMinutes ?? staleMinutes) * 60,
    [staleMinutes, waitedSeconds],
  )

  return { tickets, loading, error, advance, waitedSeconds, isLate }
}

/** «4:32» — minutos y segundos, que es como se cuenta en una cocina. */
export function formatWait(seconds: number): string {
  const minutes = Math.floor(seconds / 60)

  return `${minutes}:${String(seconds % 60).padStart(2, '0')}`
}
