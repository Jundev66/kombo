import { can, hasModule } from '@kombo/api-client'
import {
  backToDashboard,
  SupervisionBanner,
  TerminalGate,
  useDoorway,
  useSession,
} from '@kombo/shell'
import { Spinner } from '@kombo/ui'
import { Register } from './Register'

/**
 * The counter till.
 *
 * It does not navigate — no router here, as in the kitchen. A cashier with a
 * customer in front of them does not explore an app: they tap products, take
 * payment, and hand over the paper.
 *
 * Entry is by PIN rather than browser session, because this is a shared shop
 * machine: the machine's token alone sells nothing. The exception is somebody
 * who already has a session — the owner looking in — and it is labelled in full;
 * the why is in `useDoorway`.
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
   * There are food businesses with no counter — a ghost kitchen, a venture run
   * from home — and for them this screen does not exist. It is said here, in
   * full, rather than letting the cashier build a whole order and discover at
   * payment time that the route answers 404.
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
    // The height is set by this container rather than `Register`: with the
    // banner up, two elements claiming the whole screen push the charge button
    // off it.
    <div className="flex h-dvh flex-col bg-[var(--surface-sunken)]">
      {supervising && <SupervisionBanner user={capabilities.user} onLeave={backToDashboard} />}

      <Register
        /*
         * Can they void on their own?
         *
         * The same question the server asks: without `counter.void` they need
         * somebody else's PIN. Resolved with `/me` BEFORE trying, so the PIN pad
         * opens when it is needed rather than after a rejection with a customer
         * waiting.
         *
         * `needsAuthorization` is not what is checked: that holds
         * `counter.void_request`, the permission to ASK. What decides is not
         * being able to execute.
         */
        needsPin={!can(capabilities, 'counter.void')}
        // Whoever operates according to the SERVER, not whoever entered the PIN. If
        // this machine has an open session that beats the token, it shows here.
        operator={capabilities.user.name}
        // Under supervision the banner carries the exit: two ways out, one that
        // closes a shift and one that does not, is how somebody presses the wrong
        // one.
        onLeave={supervising ? null : endShift}
      />
    </div>
  )
}
