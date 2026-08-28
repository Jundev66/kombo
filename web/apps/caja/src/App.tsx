import { api, can, hasModule, type Capabilities } from '@kombo/api-client'
import { TerminalGate, terminal } from '@kombo/shell'
import { Spinner } from '@kombo/ui'
import { useCallback, useEffect, useState } from 'react'
import { Register } from './Register'

/**
 * La caja del mostrador.
 *
 * **No navega**: no hay router aquí, igual que en la cocina. Un cajero con un
 * cliente delante no explora una aplicación — toca productos, cobra, y entrega
 * el papel.
 *
 * Entra con PIN y no con sesión de navegador, porque es una máquina compartida
 * del local: el token de la máquina por sí solo no vende nada.
 */
export function App() {
  const [inside, setInside] = useState(terminal.stationToken() !== null)
  const [caps, setCaps] = useState<Capabilities | null>(null)

  const load = useCallback(() => {
    void api
      .capabilities()
      .then(setCaps)
      .catch(() => setCaps(null))
  }, [])

  useEffect(() => {
    if (inside) load()
  }, [inside, load])

  if (!inside) {
    return (
      <TerminalGate
        deviceName="Caja"
        question="¿Quién está en la caja?"
        onReady={() => setInside(true)}
      />
    )
  }

  if (caps === null) return <Spinner label="Abriendo la caja…" />

  /*
   * Hay negocios de comida sin mostrador —una cocina oculta, un
   * emprendimiento desde casa— y para ellos esta pantalla no existe. Se dice
   * aquí, con todas las letras, en vez de dejar que el cajero arme un pedido
   * entero y descubra al cobrar que la ruta responde 404.
   */
  if (!hasModule(caps, 'counter')) {
    return (
      <main className="grid min-h-dvh place-items-center p-6 text-center">
        <div>
          <h1 className="text-xl font-semibold text-[var(--text-strong)]">
            Este negocio no tiene caja
          </h1>
          <p className="mt-2 text-sm text-[var(--text-muted)]">
            Se vende por el portal y por los canales. Si hace falta cobrar en el local, se
            enciende desde el panel.
          </p>
        </div>
      </main>
    )
  }

  return (
    <Register
      /*
       * ¿Puede anular por sí sola?
       *
       * Es la misma pregunta que se hace el servidor: quien no tiene
       * `counter.void` necesita el PIN de alguien que sí. Se resuelve con
       * `/me` ANTES de intentarlo, para abrir el teclado del PIN en el momento
       * en que hace falta y no después de un rechazo con el cliente delante.
       *
       * No se mira `needsAuthorization`: ahí está `counter.void_request`, que
       * es el permiso de PEDIRLO. Lo que decide es no poder ejecutarlo.
       */
      needsPin={!can(caps, 'counter.void')}
      onLeave={() => {
        // Se borra sólo el turno: la máquina sigue dada de alta, y el
        // siguiente entra con su PIN sin volver a configurarla.
        terminal.endShift()
        setInside(false)
        setCaps(null)
      }}
    />
  )
}
