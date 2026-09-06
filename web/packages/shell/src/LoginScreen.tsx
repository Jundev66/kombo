import { ApiError } from '@kombo/api-client'
import { Button, Field, Input } from '@kombo/ui'
import { useState, type FormEvent } from 'react'
import { login, useSession } from './session'

/**
 * The sign-in screen.
 *
 * It shows the TENANT's name and logo, not the platform's — which is why `/me`
 * answers without a session. A login saying "Kombo" instead of "Arepera El
 * Sazón" looks like another product.
 *
 * And there is no "which of your businesses?" field: the subdomain already said.
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

  // 419 is "the session expired", not "wrong password". Saying the latter
  // sends the person off trying passwords that are in fact correct.
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
