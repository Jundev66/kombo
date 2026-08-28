import { Button, Field, Input, Money, Spinner, formatUsd } from '@kombo/ui'
import { useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router'
import { shopApi, type Shop, type TrackedOrder } from './api'

/**
 * Dónde va mi pedido.
 *
 * Esta pantalla es la razón de que exista el token público: sobrevive a que el
 * cliente cierre el navegador para ir a la aplicación del banco, apague el
 * teléfono, o vuelva mañana a ver qué pasó. Sin cuenta y sin contraseña.
 *
 * Se refresca **cada 10 segundos**, y no cada 3: quien espera comida mira el
 * teléfono cada tanto, no fijamente, y cada consulta se paga en datos y en
 * batería de alguien que a lo mejor está en la calle.
 */
export function TrackScreen({ shop }: { shop: Shop }) {
  const { token = '' } = useParams()

  const [order, setOrder] = useState<TrackedOrder | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    let alive = true

    async function load(): Promise<void> {
      try {
        const fresh = await shopApi.track(token)

        if (alive) {
          setOrder(fresh)
          setError(null)
        }
      } catch {
        // No se borra lo que ya se estaba viendo: el pedido sigue existiendo
        // aunque el teléfono haya perdido la señal un momento.
        if (alive) setError('Sin conexión. Seguimos intentando.')
      } finally {
        if (alive) setLoading(false)
      }
    }

    void load()
    const timer = setInterval(() => void load(), 10_000)

    return () => {
      alive = false
      clearInterval(timer)
    }
  }, [token])

  if (loading) return <Spinner label="Buscando tu pedido…" />

  if (order === null) {
    return (
      <div className="grid min-h-dvh place-items-center p-6 text-center">
        <div>
          <h1 className="text-lg font-bold text-[var(--text-strong)]">
            No encontramos ese pedido
          </h1>
          <p className="mt-2 text-sm text-[var(--text-muted)]">Revisa el enlace.</p>
          <Link to="/" className="mt-4 inline-block">
            <Button>Ver la carta</Button>
          </Link>
        </div>
      </div>
    )
  }

  const cancelado = order.status === 'cancelled'

  return (
    <div className="flex min-h-dvh flex-col gap-4 bg-[var(--surface-sunken)] p-4">
      <header className="text-center">
        <p className="text-sm text-[var(--text-muted)]">{shop.name}</p>
        <h1 className="tabular text-2xl font-bold text-[var(--text-strong)]">
          Pedido #{order.number}
        </h1>
        <p className={`mt-1 font-medium ${cancelado ? 'text-bad-500' : 'text-accent-600'}`}>
          {order.statusLabel}
        </p>

        {cancelado && order.cancellationReason != null && (
          <p className="mt-1 text-sm text-[var(--text-muted)]">{order.cancellationReason}</p>
        )}
      </header>

      {error != null && (
        <p role="status" className="text-center text-xs text-[var(--text-muted)]">
          {error}
        </p>
      )}

      {!cancelado && (
        <ol className="flex flex-col gap-3 rounded-[var(--radius-lg)] bg-[var(--surface-raised)] p-4">
          {order.steps.map((step) => (
            <li key={step.key} className="flex items-center gap-3">
              <span
                aria-hidden="true"
                className={`grid size-7 shrink-0 place-items-center rounded-full text-sm ${
                  step.done
                    ? 'bg-ok-500 text-white'
                    : 'bg-[var(--surface-sunken)] text-[var(--text-muted)]'
                }`}
              >
                {step.done ? '✓' : '·'}
              </span>

              <span
                className={
                  step.done
                    ? 'font-medium text-[var(--text-strong)]'
                    : 'text-[var(--text-muted)]'
                }
              >
                {step.label}
              </span>
            </li>
          ))}
        </ol>
      )}

      {order.needsReceipt && (
        <ReceiptForm token={order.token} shop={shop} onDone={setOrder} />
      )}

      <section className="rounded-[var(--radius-lg)] bg-[var(--surface-raised)] p-4">
        <h2 className="mb-2 font-medium text-[var(--text-strong)]">Lo que pediste</h2>

        <ul className="flex flex-col gap-1">
          {order.items.map((item, index) => (
            <li key={index} className="flex justify-between gap-3 text-sm">
              <span className="text-[var(--text-default)]">
                <span className="tabular">{item.quantity}×</span> {item.name}
                {item.modifiers.length > 0 && (
                  <span className="block text-xs text-[var(--text-muted)]">
                    {item.modifiers.join(' · ')}
                  </span>
                )}
              </span>
              <span className="tabular">{formatUsd(item.lineTotalCents)}</span>
            </li>
          ))}
        </ul>

        {order.deliveryFeeCents > 0 && (
          <p className="mt-2 flex justify-between text-sm">
            <span className="text-[var(--text-muted)]">
              Reparto{order.deliveryZoneName != null && ` · ${order.deliveryZoneName}`}
            </span>
            <span className="tabular">{formatUsd(order.deliveryFeeCents)}</span>
          </p>
        )}

        <div className="mt-3 flex items-end justify-between border-t border-[var(--surface-border)] pt-3">
          <span className="font-medium text-[var(--text-strong)]">Total</span>
          <Money cents={order.totalCents} rate={order.exchangeRate} scale="lg" />
        </div>
      </section>

      <Link to="/" className="text-center text-sm text-[var(--text-muted)] underline-offset-2 hover:underline">
        Volver a la carta
      </Link>
    </div>
  )
}

/**
 * La foto del pago móvil.
 *
 * `capture` no se pone a propósito: mucha gente paga desde la aplicación del
 * banco y guarda la **captura de pantalla**. Forzar la cámara le obligaría a
 * fotografiar su propia pantalla con otro teléfono.
 */
function ReceiptForm({
  token,
  shop,
  onDone,
}: {
  token: string
  shop: Shop
  onDone: (order: TrackedOrder) => void
}) {
  const [reference, setReference] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [sending, setSending] = useState(false)
  const fileInput = useRef<HTMLInputElement>(null)

  async function submit(): Promise<void> {
    const file = fileInput.current?.files?.[0]

    if (file === undefined) {
      setError('Elige la foto del pago.')
      return
    }

    setSending(true)
    setError(null)

    try {
      onDone(await shopApi.uploadReceipt(token, file, reference))
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : 'No se pudo enviar.')
      setSending(false)
    }
  }

  return (
    <section className="flex flex-col gap-3 rounded-[var(--radius-lg)] bg-warn-50 p-4">
      <h2 className="font-medium text-warn-700">Falta tu comprobante</h2>

      {shop.pagoMovilDetails != null && (
        <p className="text-sm whitespace-pre-line text-[var(--text-default)]">
          Paga a: {shop.pagoMovilDetails}
        </p>
      )}

      <Field label="Foto del pago" required error={error ?? undefined}>
        {({ id }) => (
          <input
            id={id}
            ref={fileInput}
            type="file"
            accept="image/*"
            className="w-full text-sm text-[var(--text-default)] file:mr-3 file:min-h-11 file:rounded-[var(--radius-md)] file:border-0 file:bg-[var(--surface-raised)] file:px-4 file:font-medium"
          />
        )}
      </Field>

      <Field label="Referencia" hint="Los últimos números, si los tienes a mano.">
        {({ id }) => (
          <Input
            id={id}
            inputMode="numeric"
            value={reference}
            onChange={(e) => setReference(e.target.value)}
          />
        )}
      </Field>

      <Button size="touch" block disabled={sending} onClick={() => void submit()}>
        {sending ? 'Enviando…' : 'Enviar el comprobante'}
      </Button>
    </section>
  )
}
