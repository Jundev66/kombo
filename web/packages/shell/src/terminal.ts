/**
 * THIS screen's credentials.
 *
 * In `localStorage`, which the browser ties to the origin — and each tenant is
 * its own origin, so one shop's tablet cannot read another's token.
 *
 * Two tokens, and the difference matters:
 *
 *   device   Registered ONCE in the tablet's life. Only good for listing names
 *            and validating a PIN. It operates nothing.
 *   station  The cook who is on right now. This one moves tickets, and what it
 *            does is recorded in their name.
 */

const DEVICE_TOKEN = 'kombo.device.token'
const DEVICE_NAME = 'kombo.device.name'
const STATION_TOKEN = 'kombo.station.token'

export const terminal = {
  deviceToken: () => localStorage.getItem(DEVICE_TOKEN),
  name: () => localStorage.getItem(DEVICE_NAME) ?? 'Cocina',

  provision(token: string, name: string): void {
    localStorage.setItem(DEVICE_TOKEN, token)
    localStorage.setItem(DEVICE_NAME, name)
  },

  stationToken: () => localStorage.getItem(STATION_TOKEN),

  startShift(token: string): void {
    localStorage.setItem(STATION_TOKEN, token)
  },

  /**
   * Signing out clears ONLY the cook's token: the tablet stays registered, so
   * the next shift signs in with a PIN rather than an email and password nobody
   * in the kitchen has.
   */
  endShift(): void {
    localStorage.removeItem(STATION_TOKEN)
  },

  /**
   * The one that counts now: the cook's if there is a shift, otherwise the
   * tablet's, which only asks who may sign in.
   */
  active: () => localStorage.getItem(STATION_TOKEN) ?? localStorage.getItem(DEVICE_TOKEN),
}
