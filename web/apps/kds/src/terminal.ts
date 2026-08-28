/**
 * Las credenciales de ESTA pantalla.
 *
 * En `localStorage`, que el navegador ata al origen — y cada negocio es su
 * propio origen. La tablet de El Sazón no puede leer el token de La Esquina
 * sin que nadie escriba una línea para impedirlo.
 *
 * Dos tokens, y la diferencia importa:
 *
 *   device   Se da de alta UNA vez en la vida de la tablet. Sólo sirve para
 *            pedir la lista de nombres y validar un PIN. No opera nada.
 *   station  El del cocinero que está ahora mismo. Éste sí mueve comandas, y
 *            lo que hace queda a su nombre.
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
   * Salir. Se borra SÓLO el del cocinero: la tablet sigue dada de alta, así
   * que el siguiente turno entra con su PIN y no hay que volver a configurarla
   * con un correo y una contraseña que nadie en la cocina tiene.
   */
  endShift(): void {
    localStorage.removeItem(STATION_TOKEN)
  },

  /**
   * El que vale ahora. El del cocinero si hay turno; si no, el de la tablet
   * —que sólo sirve para preguntar quién puede entrar—.
   */
  active: () => localStorage.getItem(STATION_TOKEN) ?? localStorage.getItem(DEVICE_TOKEN),
}
