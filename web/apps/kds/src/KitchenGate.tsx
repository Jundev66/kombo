import { Button, Field, Input } from '@kombo/ui'
import { useEffect, useState, type FormEvent } from 'react'
import { kitchen, type Staff } from './api'
import { terminal } from './terminal'

/**
 * Cómo se entra a la cocina. **Dos puertas, no una.**
 *
 * La tablet de la cocina es otra máquina: con una sesión de navegador esto
 * sólo funcionaría donde alguien abrió el panel. Por eso hay un alta —una vez
 * en la vida del aparato, con correo y contraseña— y después un PIN de cuatro
 * dígitos, que es lo único que se puede teclear con las manos ocupadas.
 */
export function KitchenGate({ onReady }: { onReady: () => void }) {
  const [businessName, setBusinessName] = useState('Cocina')
  const [step, setStep] = useState<'device' | 'person'>(
    terminal.deviceToken() === null ? 'device' : 'person',
  )

  useEffect(() => {
    void kitchen.businessName().then(setBusinessName)
  }, [])

  return (
    <main className="grid min-h-dvh place-items-center bg-[var(--surface-sunken)] p-4">
      <div className="w-full max-w-sm rounded-[var(--radius-lg)] bg-[var(--surface-raised)] p-6">
        {/* El nombre del NEGOCIO, no el de la plataforma: quien enciende la
            tablet tiene que saber que está en el local correcto. */}
        <h1 className="mb-6 text-center text-xl font-bold text-[var(--text-strong)]">
          {businessName}
        </h1>

        {step === 'device' ? (
          <DeviceStep onDone={() => setStep('person')} />
        ) : (
          <PersonStep onReady={onReady} onForget={() => setStep('device')} />
        )}
      </div>
    </main>
  )
}

/**
 * Alta de la pantalla. La hace una vez alguien con contraseña —el dueño, el
 * encargado— cuando se pone la tablet.
 */
function DeviceStep({ onDone }: { onDone: () => void }) {
  const [name, setName] = useState('Cocina')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [sending, setSending] = useState(false)

  async function onSubmit(event: FormEvent): Promise<void> {
    event.preventDefault()
    setSending(true)
    setError(null)

    try {
      const { token } = await kitchen.provision(email, password, name)
      terminal.provision(token, name)
      onDone()
    } catch {
      setError('Ese correo y esa contraseña no entran.')
      setSending(false)
    }
  }

  return (
    <form onSubmit={onSubmit} className="flex flex-col gap-4">
      <p className="text-center text-sm text-[var(--text-muted)]">
        Sólo la primera vez: da de alta esta pantalla.
      </p>

      <Field label="Nombre de la pantalla" hint="Para reconocerla desde el panel.">
        {({ id }) => <Input id={id} value={name} onChange={(e) => setName(e.target.value)} />}
      </Field>

      <Field label="Correo" required>
        {({ id }) => (
          <Input id={id} type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
        )}
      </Field>

      <Field label="Contraseña" required error={error ?? undefined}>
        {({ id, invalid }) => (
          <Input
            id={id}
            type="password"
            value={password}
            invalid={invalid}
            onChange={(e) => setPassword(e.target.value)}
          />
        )}
      </Field>

      <Button type="submit" size="touch" block disabled={sending}>
        Dar de alta
      </Button>
    </form>
  )
}

/**
 * Quién está en la cocina ahora mismo.
 *
 * Nombres para tocar, no un campo de correo: nadie escribe
 * `carlos@elsazon.test` con las manos ocupadas.
 */
function PersonStep({ onReady, onForget }: { onReady: () => void; onForget: () => void }) {
  const [staff, setStaff] = useState<Staff[]>([])
  const [chosen, setChosen] = useState<Staff | null>(null)
  const [pin, setPin] = useState('')
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    kitchen
      .staff()
      .then((r) => setStaff(r.staff))
      .catch(() => {
        // El token de la tablet ya no vale: hay que darla de alta otra vez.
        terminal.endShift()
        onForget()
      })
  }, [onForget])

  async function submit(value: string): Promise<void> {
    if (chosen === null) return

    try {
      const { token } = await kitchen.pin(chosen.id, value, terminal.name())
      terminal.startShift(token)
      onReady()
    } catch {
      setError('Ese PIN no es. Inténtalo otra vez.')
      setPin('')
    }
  }

  if (chosen === null) {
    return (
      <div className="flex flex-col gap-2">
        <h2 className="mb-2 text-center text-[var(--text-default)]">¿Quién está en la cocina?</h2>

        {staff.map((person) => (
          <button
            key={person.id}
            type="button"
            onClick={() => setChosen(person)}
            className="flex min-h-touch items-center justify-between rounded-[var(--radius-md)] border border-[var(--surface-border)] px-4 text-left"
          >
            <span className="font-medium text-[var(--text-strong)]">{person.name}</span>
            <span className="text-sm text-[var(--text-muted)]">{person.roleName}</span>
          </button>
        ))}
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <h2 className="text-center text-[var(--text-default)]">Hola, {chosen.name}</h2>

      <div className="flex justify-center gap-3" aria-hidden="true">
        {[0, 1, 2, 3].map((i) => (
          <span
            key={i}
            className={`size-4 rounded-full ${
              i < pin.length ? 'bg-brand-500' : 'bg-[var(--surface-border)]'
            }`}
          />
        ))}
      </div>

      {error != null && (
        <p role="alert" className="text-center text-sm font-medium text-bad-500">
          {error}
        </p>
      )}

      <div className="grid grid-cols-3 gap-2">
        {['1', '2', '3', '4', '5', '6', '7', '8', '9', '', '0', '⌫'].map((key, i) =>
          key === '' ? (
            <span key={i} />
          ) : (
            <button
              key={i}
              type="button"
              aria-label={key === '⌫' ? 'Borrar' : key}
              onClick={() => {
                if (key === '⌫') {
                  setPin((p) => p.slice(0, -1))
                  return
                }

                const next = (pin + key).slice(0, 4)
                setPin(next)

                // Se envía SOLO al llegar al cuarto dígito. Un botón de
                // confirmar de más es un toque de más con las manos ocupadas.
                if (next.length === 4) void submit(next)
              }}
              className="min-h-touch rounded-[var(--radius-md)] bg-[var(--surface-sunken)] text-xl font-medium text-[var(--text-strong)]"
            >
              {key}
            </button>
          ),
        )}
      </div>

      <Button variant="ghost" block onClick={() => { setChosen(null); setPin(''); setError(null) }}>
        No soy yo
      </Button>
    </div>
  )
}
