import { api } from '@kombo/api-client'
import { Button, Field, Input } from '@kombo/ui'
import { useEffect, useState, type FormEvent } from 'react'
import { terminal } from './terminal'

/**
 * Who may sign in at this screen. The server answers with the device token,
 * which is good for nothing else.
 */
export interface Staff {
  id: string
  name: string
  roleName: string | null
}

const doorway = {
  /** The TENANT's name. `/me` answers without a session, precisely for this. */
  businessName: async (fallback: string): Promise<string> => {
    try {
      const caps = await api.capabilities()

      return caps.tenant?.name ?? fallback
    } catch {
      return fallback
    }
  },

  provision: (email: string, password: string, device: string) =>
    api.post<{ token: string }>('/auth/device', { email, password, device }),

  staff: () => api.get<{ staff: Staff[] }>('/auth/staff'),

  pin: (userId: string, pin: string, device: string) =>
    api.post<{ token: string; user: { name: string } }>('/auth/pin', {
      user_id: userId,
      pin,
      device,
    }),
}

interface TerminalGateProps {
  onReady: () => void
  /** The device's default name: "Cocina", "Caja 1". */
  deviceName: string
  /** "Who is in the kitchen?", "Who is at the till?" */
  question: string
}

/**
 * How a shop-floor screen is entered. Two doors, not one.
 *
 * The kitchen tablet and the counter machine are other machines: with a browser
 * session this would only work where somebody opened the dashboard. So there is
 * a registration — once in the device's life, with email and password — and
 * then a four-digit PIN, the only thing that can be typed with full hands.
 *
 * The kitchen and the till share this gate on purpose: two copies of a door
 * diverge exactly in the detail that made them safe.
 */
export function TerminalGate({ onReady, deviceName, question }: TerminalGateProps) {
  const [businessName, setBusinessName] = useState(deviceName)
  const [step, setStep] = useState<'device' | 'person'>(
    terminal.deviceToken() === null ? 'device' : 'person',
  )

  useEffect(() => {
    void doorway.businessName(deviceName).then(setBusinessName)
  }, [deviceName])

  return (
    <main className="grid min-h-dvh place-items-center bg-[var(--surface-sunken)] p-4">
      <div className="w-full max-w-sm rounded-[var(--radius-lg)] bg-[var(--surface-raised)] p-6">
        {/* The TENANT's name, not the platform's: whoever switches the tablet on
            has to know they are in the right shop. */}
        <h1 className="mb-6 text-center text-xl font-bold text-[var(--text-strong)]">
          {businessName}
        </h1>

        {step === 'device' ? (
          <DeviceStep defaultName={deviceName} onDone={() => setStep('person')} />
        ) : (
          <PersonStep question={question} onReady={onReady} onForget={() => setStep('device')} />
        )}
      </div>
    </main>
  )
}

/**
 * Registering the screen. Done once by somebody with a password — the owner or
 * the manager — when the tablet is set up.
 */
function DeviceStep({ defaultName, onDone }: { defaultName: string; onDone: () => void }) {
  const [name, setName] = useState(defaultName)
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [sending, setSending] = useState(false)

  async function onSubmit(event: FormEvent): Promise<void> {
    event.preventDefault()
    setSending(true)
    setError(null)

    try {
      const { token } = await doorway.provision(email, password, name)
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
 * Who is at the machine right now.
 *
 * Names to tap, not an email field: nobody types `carlos@elsazon.test` with
 * their hands full.
 */
function PersonStep({
  question,
  onReady,
  onForget,
}: {
  question: string
  onReady: () => void
  onForget: () => void
}) {
  const [staff, setStaff] = useState<Staff[]>([])
  const [chosen, setChosen] = useState<Staff | null>(null)
  const [pin, setPin] = useState('')
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    doorway
      .staff()
      .then((r) => setStaff(r.staff))
      .catch(() => {
        // The tablet's token is no longer valid: it has to be registered again.
        terminal.endShift()
        onForget()
      })
  }, [onForget])

  async function submit(value: string): Promise<void> {
    if (chosen === null) return

    try {
      const { token } = await doorway.pin(chosen.id, value, terminal.name())
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
        <h2 className="mb-2 text-center text-[var(--text-default)]">{question}</h2>

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

                // Submitted only on reaching the fourth digit. An extra confirm button is
                // an extra tap with full hands.
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
