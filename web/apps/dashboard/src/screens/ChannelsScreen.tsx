import { ApiError } from '@kombo/api-client'
import { Badge, Button, Card, Field, Input, Spinner, Page} from '@kombo/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { CHANNEL_LABELS, channels, type Channel } from '../api/channels'

/**
 * Connecting WhatsApp and Telegram.
 *
 * The most dangerous screen in the dashboard: what gets pasted here is a token
 * that writes to every customer in the tenant's name. Which is why the token
 * never comes back — not even masked — and only whether one is set and when it
 * was last used is reported.
 *
 * And why the webhook address is computed here: pasting it by hand and getting
 * one character wrong is an afternoon lost wondering why the bot is silent.
 */
export function ChannelsScreen() {
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState<string | null>(null)

  const list = useQuery({ queryKey: ['channels'], queryFn: channels.list })

  const disconnectAccount = useMutation({
    mutationFn: (channel: string) => channels.disconnect(channel),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['channels'] }),
  })

  if (list.isLoading) return <Spinner />

  return (
    <Page width="reading" className="flex flex-col gap-4">
      <h1 className="text-xl font-bold text-[var(--text-strong)]">WhatsApp y Telegram</h1>

      <p className="text-sm text-[var(--text-muted)]">
        Por aquí llega la gente. El bot enseña la carta, avisa cuando el pedido está listo, y
        pasa la conversación a una persona cuando el cliente lo pide.
      </p>

      {list.data?.map((channel) => (
        <Card key={channel.channel} className="p-4">
          <section
            aria-label={CHANNEL_LABELS[channel.channel] ?? channel.channel}
            className="flex flex-col gap-3"
          >
          <div className="flex items-center gap-3">
            <h2 className="flex-1 font-semibold text-[var(--text-strong)]">
              {CHANNEL_LABELS[channel.channel] ?? channel.channel}
            </h2>

            {channel.connected && channel.isActive ? (
              <Badge tone="ok">Conectado</Badge>
            ) : (
              <Badge>Sin conectar</Badge>
            )}
          </div>

          {channel.connected && channel.isActive && (
            <>
              <p className="text-sm text-[var(--text-muted)]">
                {channel.label ?? channel.externalId}
                {channel.lastMessageAt != null && ' · último mensaje hace poco'}
              </p>

              <WebhookUrl url={channel.webhookUrl} />
            </>
          )}

          {editing === channel.channel ? (
            <ConnectForm
              channel={channel.channel}
              onDone={() => {
                setEditing(null)
                void queryClient.invalidateQueries({ queryKey: ['channels'] })
              }}
              onCancel={() => setEditing(null)}
            />
          ) : (
            <div className="flex gap-2">
              <Button variant="secondary" onClick={() => setEditing(channel.channel)}>
                {channel.connected && channel.isActive ? 'Cambiar el token' : 'Conectar'}
              </Button>

              {channel.connected && channel.isActive && (
                <Button
                  variant="ghost"
                  onClick={() => disconnectAccount.mutate(channel.channel)}
                  // Switched off, not deleted: the last few months' conversations are still
                  // there and have to be readable.
                  aria-label={`Desconectar ${CHANNEL_LABELS[channel.channel]}`}
                >
                  Desconectar
                </Button>
              )}
            </div>
          )}
          </section>
        </Card>
      ))}
    </Page>
  )
}

/** The address to paste into Meta's console, ready to copy. */
function WebhookUrl({ url }: { url: string }) {
  const [copied, setCopied] = useState(false)

  return (
    <div className="flex items-center gap-2 rounded-[var(--radius-md)] bg-[var(--surface-sunken)] p-3">
      <code className="min-w-0 flex-1 truncate text-xs text-[var(--text-default)]">{url}</code>

      <Button
        size="sm"
        variant="ghost"
        onClick={() => {
          void navigator.clipboard.writeText(url)
          setCopied(true)
        }}
      >
        {copied ? 'Copiada' : 'Copiar'}
      </Button>
    </div>
  )
}

function ConnectForm({
  channel,
  onDone,
  onCancel,
}: {
  channel: Channel['channel']
  onDone: () => void
  onCancel: () => void
}) {
  const [externalId, setExternalId] = useState('')
  const [token, setToken] = useState('')
  const [secret, setSecret] = useState('')
  const [error, setError] = useState<string | null>(null)

  const save = useMutation({
    mutationFn: () =>
      channels.connect(channel, {
        external_id: externalId.trim(),
        webhook_secret: secret.trim(),
        credentials:
          channel === 'whatsapp'
            ? { access_token: token.trim() }
            : { bot_token: token.trim() },
      }),
    onSuccess: onDone,
    onError: (failure: unknown) =>
      setError(failure instanceof ApiError ? failure.message : 'No se pudo guardar.'),
  })

  const isWhatsApp = channel === 'whatsapp'

  return (
    <div className="flex flex-col gap-3 rounded-[var(--radius-md)] bg-[var(--surface-sunken)] p-4">
      <Field
        label={isWhatsApp ? 'Identificador del número' : 'Identificador del bot'}
        hint={isWhatsApp ? 'El «phone number ID» de la consola de Meta.' : 'El número que va antes de los dos puntos en el token.'}
        required
      >
        {({ id }) => (
          <Input id={id} value={externalId} onChange={(e) => setExternalId(e.target.value)} />
        )}
      </Field>

      <Field
        label={isWhatsApp ? 'Token permanente' : 'Token del bot'}
        hint="No se vuelve a mostrar. Para cambiarlo, pega otro."
        required
      >
        {({ id }) => (
          <Input id={id} type="password" value={token} onChange={(e) => setToken(e.target.value)} />
        )}
      </Field>

      <Field
        label="Secreto del webhook"
        hint="Lo eliges tú, y lo pegas también en la consola del canal. Es lo que prueba que un mensaje viene de verdad de ahí."
        required
        error={error ?? undefined}
      >
        {({ id, invalid }) => (
          <Input
            id={id}
            value={secret}
            invalid={invalid}
            onChange={(e) => setSecret(e.target.value)}
          />
        )}
      </Field>

      <div className="flex gap-2">
        <Button variant="ghost" onClick={onCancel}>
          Mejor no
        </Button>

        <Button
          disabled={
            save.isPending ||
            externalId.trim() === '' ||
            token.trim() === '' ||
            secret.trim().length < 8
          }
          onClick={() => save.mutate()}
        >
          {save.isPending ? 'Guardando…' : 'Guardar'}
        </Button>
      </div>
    </div>
  )
}
