import { ApiError } from '@kombo/api-client'
import { Badge, Button, Card, Field, Input, Spinner, Page} from '@kombo/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { CHANNEL_LABELS, channels, type Channel } from '../api/channels'

/**
 * Conectar WhatsApp y Telegram.
 *
 * Es la pantalla más peligrosa del panel: lo que se pega aquí es un token con
 * el que se puede escribir a todos los clientes del negocio en su nombre. Por
 * eso el token **nunca vuelve** —ni enmascarado— y sólo se dice si hay uno
 * puesto y cuándo se usó por última vez.
 *
 * Y por eso la dirección del webhook se calcula aquí: pegarla a mano y
 * equivocarse en un carácter es una tarde perdida buscando por qué el bot no
 * contesta.
 */
export function ChannelsScreen() {
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState<string | null>(null)

  const lista = useQuery({ queryKey: ['channels'], queryFn: channels.list })

  const desconectar = useMutation({
    mutationFn: (channel: string) => channels.disconnect(channel),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['channels'] }),
  })

  if (lista.isLoading) return <Spinner />

  return (
    <Page ancho="lectura" className="flex flex-col gap-4">
      <h1 className="text-xl font-bold text-[var(--text-strong)]">WhatsApp y Telegram</h1>

      <p className="text-sm text-[var(--text-muted)]">
        Por aquí llega la gente. El bot enseña la carta, avisa cuando el pedido está listo, y
        pasa la conversación a una persona cuando el cliente lo pide.
      </p>

      {lista.data?.map((canal) => (
        <Card key={canal.channel} className="p-4">
          <section
            aria-label={CHANNEL_LABELS[canal.channel] ?? canal.channel}
            className="flex flex-col gap-3"
          >
          <div className="flex items-center gap-3">
            <h2 className="flex-1 font-semibold text-[var(--text-strong)]">
              {CHANNEL_LABELS[canal.channel] ?? canal.channel}
            </h2>

            {canal.connected && canal.isActive ? (
              <Badge tone="ok">Conectado</Badge>
            ) : (
              <Badge>Sin conectar</Badge>
            )}
          </div>

          {canal.connected && canal.isActive && (
            <>
              <p className="text-sm text-[var(--text-muted)]">
                {canal.label ?? canal.externalId}
                {canal.lastMessageAt != null && ' · último mensaje hace poco'}
              </p>

              <WebhookUrl url={canal.webhookUrl} />
            </>
          )}

          {editing === canal.channel ? (
            <ConnectForm
              channel={canal.channel}
              onDone={() => {
                setEditing(null)
                void queryClient.invalidateQueries({ queryKey: ['channels'] })
              }}
              onCancel={() => setEditing(null)}
            />
          ) : (
            <div className="flex gap-2">
              <Button variant="secondary" onClick={() => setEditing(canal.channel)}>
                {canal.connected && canal.isActive ? 'Cambiar el token' : 'Conectar'}
              </Button>

              {canal.connected && canal.isActive && (
                <Button
                  variant="ghost"
                  onClick={() => desconectar.mutate(canal.channel)}
                  // Se apaga, no se borra: las conversaciones de los últimos
                  // meses siguen ahí y tienen que poder leerse.
                  aria-label={`Desconectar ${CHANNEL_LABELS[canal.channel]}`}
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

/** La dirección que se pega en la consola de Meta, lista para copiar. */
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

  const guardar = useMutation({
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

  const esWhatsApp = channel === 'whatsapp'

  return (
    <div className="flex flex-col gap-3 rounded-[var(--radius-md)] bg-[var(--surface-sunken)] p-4">
      <Field
        label={esWhatsApp ? 'Identificador del número' : 'Identificador del bot'}
        hint={esWhatsApp ? 'El «phone number ID» de la consola de Meta.' : 'El número que va antes de los dos puntos en el token.'}
        required
      >
        {({ id }) => (
          <Input id={id} value={externalId} onChange={(e) => setExternalId(e.target.value)} />
        )}
      </Field>

      <Field
        label={esWhatsApp ? 'Token permanente' : 'Token del bot'}
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
            guardar.isPending ||
            externalId.trim() === '' ||
            token.trim() === '' ||
            secret.trim().length < 8
          }
          onClick={() => guardar.mutate()}
        >
          {guardar.isPending ? 'Guardando…' : 'Guardar'}
        </Button>
      </div>
    </div>
  )
}
