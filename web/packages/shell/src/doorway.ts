import { useCallback, useEffect, useState } from 'react'
import { boot, useSession } from './session'
import { terminal } from './terminal'

/**
 * Cómo se entra a una pantalla del local. **Tres maneras, y las decide `/me`.**
 *
 * Antes el orden era al revés: se miraba `localStorage`, y sólo después de
 * pasar la puerta se preguntaba al servidor quién había entrado. Eso hacía dos
 * cosas mal.
 *
 * Dejaba fuera al dueño, que acababa de entrar al panel con su contraseña y
 * tenía que dar de alta el aparato y teclear un PIN para mirar su propia caja.
 *
 * Y, peor, mentía: Sanctum prefiere la cookie de sesión al token, así que en
 * una máquina donde alguien dejó el panel abierto, el cajero ponía su PIN y
 * todas las operaciones salían a nombre del otro. La pantalla no tenía forma de
 * saberlo porque nunca preguntaba.
 *
 * Preguntando primero, **quien manda es siempre el servidor**:
 *
 *   supervision  Hay sesión de navegador y no hay turno. El dueño mirando.
 *   shift        Hay turno en esta máquina. El de siempre.
 *   gate         Ninguna de las dos: se pide el alta y el PIN.
 *
 * Por qué se invirtió el arranque, y la trampa que tapa: KMB-0005.
 *
 * En los dos primeros casos, `capabilities.user` es quien opera DE VERDAD —lo
 * dice `/me`, no el token guardado—, así que las pantallas enseñan ese nombre y
 * la trampa de arriba deja de ser invisible.
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

  /** Alguien acaba de poner su PIN: hay que volver a preguntar quién es. */
  const enter = useCallback(() => {
    setShift(true)
    void boot()
  }, [])

  /**
   * Cerrar el turno. Se borra sólo el token de la persona: la máquina sigue
   * dada de alta y el siguiente entra con su PIN sin volver a configurarla.
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
 * Volver al panel desde una pantalla del local.
 *
 * Recarga entera y no navegación de router: son aplicaciones distintas servidas
 * bajo el mismo origen, y no comparten historial.
 */
export function backToPanel(): void {
  window.location.href = '/panel/'
}
