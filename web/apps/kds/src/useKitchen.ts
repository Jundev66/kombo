import { useCallback, useEffect, useRef, useState } from 'react'
import { kitchen, type Ticket } from './api'

/**
 * The screen's tickets.
 *
 * Polled every 5 seconds rather than over a websocket. A socket means open
 * connections, reconnection, heartbeats and one more piece of state that can
 * stick without anyone noticing. A kitchen tablet on bad wifi recovers from a
 * poll on its own; from a dropped socket it does not. And five seconds of lag
 * in a kitchen is unnoticeable.
 */
export function useKitchen() {
  const [tickets, setTickets] = useState<Ticket[]>([])
  const [staleMinutes, setStaleMinutes] = useState(15)
  // How many live tickets do not fit on screen. If not zero, it is said.
  const [hidden, setHidden] = useState(0)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)

  // When the last response arrived. The stopwatch adds the seconds since, so
  // it does not depend on the tablet's clock.
  const fetchedAt = useRef(Date.now())
  const [, tick] = useState(0)

  const load = useCallback(async () => {
    try {
      const { data, meta } = await kitchen.tickets()

      setTickets(data)
      setStaleMinutes(meta.staleMinutes)
      setHidden(meta.hidden)
      setError(null)
      fetchedAt.current = Date.now()
    } catch {
      /*
       * On failure the screen is NOT cleared.
       *
       * A kitchen with no connection still has those orders on the griddle.
       * Emptying the list because the wifi blinked would lose tickets that are
       * physically there.
       */
      setError('Sin conexión')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void load()

    const polling = setInterval(() => void load(), 5_000)
    // A local tick every second, only so the stopwatch does not jump in fives.
    const clock = setInterval(() => tick((n) => n + 1), 1_000)

    return () => {
      clearInterval(polling)
      clearInterval(clock)
    }
  }, [load])

  /**
   * Advances a ticket, painting the change BEFORE the response.
   *
   * Tapping "Ready" and seeing nothing for half a second means tapping it
   * again, and in a kitchen that is what happens. If the server says no, the
   * next poll puts it back.
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

  /** How long it has been waiting, right now. */
  const waitedSeconds = useCallback(
    (ticket: Ticket): number =>
      ticket.waitingSeconds + Math.floor((Date.now() - fetchedAt.current) / 1000),
    [],
  )

  /**
   * Is it running late?
   *
   * The PRODUCT's time is used when known — a grill is not an arepa — with the
   * tenant's threshold as a safety net when it is not.
   */
  const isLate = useCallback(
    (ticket: Ticket): boolean =>
      waitedSeconds(ticket) >= (ticket.prepMinutes ?? staleMinutes) * 60,
    [staleMinutes, waitedSeconds],
  )

  return { tickets, hidden, loading, error, advance, waitedSeconds, isLate }
}

/** "4:32" — minutes and seconds, which is how a kitchen counts. */
export function formatWait(seconds: number): string {
  const minutes = Math.floor(seconds / 60)

  return `${minutes}:${String(seconds % 60).padStart(2, '0')}`
}
