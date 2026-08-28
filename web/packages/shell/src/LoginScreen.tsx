import { ApiError } from '@kombo/api-client'
import { Button, Field, Input } from '@kombo/ui'
import { useState, type FormEvent } from 'react'
import { login, useSession } from './session'

/**
 * La pantalla de entrar.
 *
 * Muestra el nombre y el logo **del negocio**, no los de la plataforma. Por eso
 * `/me` responde sin sesión: un login que dice «Kombo» en vez de «Arepera El
 * Sazón» parece de otro producto y siembra la duda de si uno está donde cree.
 *
 * Y **no hay campo «¿a cuál de tus negocios?»**: el subdominio ya lo dijo.
 */
export function LoginScreen() {
  const { capabilities } = useSession()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [sending, setSending] = useState(false)

  async function onSubmit(event: FormEvent): Promise<void> {
    event.preventDefault()
    setSending(true)
    setError(null)

    try {
      await login(email, password)
    } catch (caught) {
      setError(messageFor(caught))
      setSending(false)
    }
  }

  return (
    <main className="grid min-h-dvh place-items-center bg-[var(--surface-sunken)] p-4">
      <div className="w-full max-w-sm rounded-[var(--radius-lg)] bg-[var(--surface-raised)] p-6 shadow-[var(--shadow-card)]">
        <header className="mb-6 text-center">
          {capabilities?.tenant?.logoUrl != null && (
            <img
              src={capabilities.tenant.logoUrl}
              alt=""
              className="mx-auto mb-3 h-14 w-14 rounded-[var(--radius-md)] object-cover"
            />
          )}
          <h1 className="text-xl font-bold text-[var(--text-strong)]">
            {capabilities?.tenant?.name ?? 'Kombo'}
          </h1>
        </header>

        <form onSubmit={onSubmit} className="flex flex-col gap-4">
          <Field label="Correo" required>
            {({ id, invalid }) => (
              <Input
                id={id}
                type="email"
                autoComplete="username"
                autoFocus
                value={email}
                invalid={invalid}
                onChange={(e) => setEmail(e.target.value)}
              />
            )}
          </Field>

          <Field label="Contraseña" required error={error ?? undefined}>
            {({ id, invalid }) => (
              <Input
                id={id}
                type="password"
                autoComplete="current-password"
                value={password}
                invalid={invalid}
                onChange={(e) => setPassword(e.target.value)}
              />
            )}
          </Field>

          <Button type="submit" block disabled={sending}>
            {sending ? 'Entrando…' : 'Entrar'}
          </Button>
        </form>
      </div>
    </main>
  )
}

function messageFor(error: unknown): string {
  if (!(error instanceof ApiError)) {
    return 'No se pudo contactar al servidor.'
  }

  // 419 es «la sesión caducó», no «la contraseña está mal». Decir lo segundo
  // manda a la persona a probar contraseñas que sí son correctas.
  if (error.status === 419) {
    return 'La sesión caducó. Recarga la página e inténtalo otra vez.'
  }

  const body = error.body
  if (typeof body === 'object' && body !== null && 'errors' in body) {
    const errors = (body as { errors: Record<string, string[]> }).errors
    const first = Object.values(errors)[0]?.[0]
    if (first) return first
  }

  return error.message
}
