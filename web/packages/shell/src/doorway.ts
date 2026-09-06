import { useCallback, useEffect, useState } from 'react'
import { boot, useSession } from './session'
import { terminal } from './terminal'

/**
 * How a shop-floor screen is entered. Three ways, and `/me` decides.
 *
 * The order used to be reversed: `localStorage` was read, and only after the
 * door had been passed was the server asked who came in. That did two things
 * wrong — it locked out the owner, who had just signed into the dashboard, and
 * worse, it lied: Sanctum prefers the session cookie over the token, so where
 * somebody left the dashboard open, the cashier's PIN produced operations in
 * the other person's name with no way for the screen to know.
 *
 * Asking first, the SERVER always decides:
 *
 *   supervision  A browser session and no shift. The owner looking.
 *   shift        A shift on this machine. The usual case.
 *   gate         Neither: registration and PIN are asked for.
 *
 * Why boot was inverted, and the trap it covers: KMB-0005.
 */
export type EntryMode = 'gate' | 'shift' | 'supervision'

export function useDoorway(): {
  mode: EntryMode
  enter: () => void
  endShift: () => void
} {
  const { capabilities } = useSession()
  const [shift, setShift] = useState(() => terminal.stationToken() !== null)

  useEffect(() => {
    void boot()
  }, [])

  /** Somebody just entered their PIN: ask again who they are. */
  const enter = useCallback(() => {
    setShift(true)
    void boot()
  }, [])

  /**
   * Closing the shift. Only the person's token is cleared: the machine stays
   * registered and the next person signs in with their PIN.
   */
  const endShift = useCallback(() => {
    terminal.endShift()
    setShift(false)
    void boot()
  }, [])

  const mode: EntryMode = shift ? 'shift' : capabilities?.user != null ? 'supervision' : 'gate'

  return { mode, enter, endShift }
}

/**
 * Back to the dashboard from a shop-floor screen.
 *
 * A full reload rather than router navigation: these are separate apps served
 * under the same origin, and they share no history.
 */
export function backToDashboard(): void {
  window.location.href = '/dashboard/'
}
