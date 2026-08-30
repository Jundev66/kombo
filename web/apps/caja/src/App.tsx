import { can, hasModule } from '@kombo/api-client'
import {
  backToPanel,
  SupervisionBanner,
  TerminalGate,
  useDoorway,
  useSession,
} from '@kombo/shell'
import { Spinner } from '@kombo/ui'
import { Register } from './Register'

/**
 * La caja del mostrador.
 *
 * **No navega**: no hay router aquí, igual que en la cocina. Un cajero con un
 * cliente delante no explora una aplicación — toca productos, cobra, y entrega
 * el papel.
 *
 * Entra con PIN y no con sesión de navegador, porque es una máquina compartida
 * del local: el token de la máquina por sí solo no vende nada. La excepción es
 * quien ya tiene sesión —el dueño mirando desde su teléfono—, y va marcada con
 * todas las letras; el porqué está en `useDoorway`.
 */
export function App() {
  const { capabilities, status, error } = useSession()
  const { mode, enter, endShift } = useDoorway()

  if (status === 'loading') return <Spinner label="Abriendo la caja…" />

  if (status === 'unavailable') {
    return (
      <main className="grid min-h-dvh place-items-center p-6 text-center">
        <div>
          <h1 className="text-lg font-bold text-[var(--text-strong)]">
            No se pudo contactar al servidor
          </h1>
          <p className="mt-2 text-sm text-[var(--text-muted)]">
            Revisa la conexión. Si el problema sigue, avísanos.
          </p>
          {error != null && (
            <p className="mt-4 font-mono text-xs text-[var(--text-muted)]">{error}</p>
          )}
        </div>
      </main>
    )
  }

  if (mode === 'gate') {
    return <TerminalGate deviceName="Caja" question="¿Quién está en la caja?" onReady={enter} />
  }

  if (capabilities?.user == null) return <Spinner label="Abriendo la caja…" />

  /*
   * Hay negocios de comida sin mostrador —una cocina oculta, un
   * emprendimiento desde casa— y para ellos esta pantalla no existe. Se dice
   * aquí, con todas las letras, en vez de dejar que el cajero arme un pedido
   * entero y descubra al cobrar que la ruta responde 404.
   */
  if (!hasModule(capabilities, 'counter')) {
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

  const supervising = mode === 'supervision'

  return (
    // La altura la manda este contenedor y no `Register`: con la banda puesta,
    // dos elementos reclamando la pantalla entera dejan el botón de cobrar
    // fuera de ella.
    <div className="flex h-dvh flex-col bg-[var(--surface-sunken)]">
      {supervising && <SupervisionBanner user={capabilities.user} onLeave={backToPanel} />}

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
        needsPin={!can(capabilities, 'counter.void')}
        // Quien opera según el SERVIDOR, no quien puso el PIN. Si en esta
        // máquina hay una sesión abierta que gana al token, aquí se ve.
        operator={capabilities.user.name}
        // En supervisión la salida la lleva la banda: dos formas de irse, una
        // que cierra turno y otra que no, es la manera de que alguien pulse la
        // que no quería.
        onLeave={supervising ? null : endShift}
      />
    </div>
  )
}
