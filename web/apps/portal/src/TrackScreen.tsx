import { Button, Field, Input, Money, Spinner, formatUsd } from '@kombo/ui'
import { useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router'
import { shopApi, type Shop, type TrackedOrder } from './api'
import { ShopHeader } from './ShopHeader'

/**
 * Where my order is.
 *
 * This screen is the reason the public token exists: it survives the customer
 * closing the browser to visit the banking app, switching the phone off, or
 * coming back tomorrow. No account and no password.
 *
 * It refreshes every 10 seconds rather than every 3: somebody waiting for food
 * glances at their phone now and then, and every request costs data and battery.
 *
 * The screen's order follows the questions as they are asked: what do I have to
 * do (the receipt), where is it, where is it going, and how much was it.
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
        // What was already showing is not cleared: the order still exists even if
        // the phone lost signal for a moment.
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

  const cancelledOrder = order.status === 'cancelled'

  return (
    // The screen fills: content grows and the footer anchors at the bottom.
    // It used to bunch at the top with half a phone of grey below.
    <div className="flex min-h-dvh flex-col bg-[var(--surface-sunken)]">
      <ShopHeader
        shop={shop}
        // THIS page's heading is the order, not the brand: it is the number the
        // customer reads aloud when they call to ask.
        as="p"
        subtitle={
          <div>
            <h1 className="tabular text-2xl font-bold text-[var(--text-strong)]">
              Pedido #{order.number}
            </h1>
            <p className="text-sm text-[var(--text-muted)]">{order.serviceTypeLabel}</p>
          </div>
        }
      />

      {/* Capped: a tracking column a metre and a half wide does not read
          better, it only separates the status from the amount. */}
      <div className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-4 p-4">
        {/* First comes what THEY have to do, not what we are doing: while the
            receipt is missing there is no order to track. */}
        {order.needsReceipt && (
          <PaymentDeadline seconds={order.expiresInSeconds} minutes={shop.paymentWindowMinutes} />
        )}

        {cancelledOrder ? (
          <section className="rounded-[var(--radius-lg)] bg-bad-50 p-4">
            <h2 className="font-semibold text-bad-700">Este pedido se canceló</h2>
            {order.cancellationReason != null && (
              <p className="mt-1 text-sm text-bad-700">{order.cancellationReason}</p>
            )}
          </section>
        ) : (
          <Progress order={order} />
        )}

        {order.needsReceipt && <ReceiptForm token={order.token} shop={shop} onDone={setOrder} />}

        {/* Where it is going. The customer is the only one who can see the
            address is wrong, and a delivery to the wrong house is paid for by
            the tenant. */}
        {order.deliveryAddress != null && (
          <section className="rounded-[var(--radius-lg)] bg-[var(--surface-raised)] p-4">
            <h2 className="text-sm font-medium text-[var(--text-muted)]">Lo llevamos a</h2>
            <p className="mt-1 text-[var(--text-strong)]">{order.deliveryAddress}</p>
            {order.deliveryZoneName != null && (
              <p className="text-sm text-[var(--text-muted)]">{order.deliveryZoneName}</p>
            )}
          </section>
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

          {order.notes != null && order.notes !== '' && (
            <p className="mt-2 border-t border-[var(--surface-border)] pt-2 text-sm text-[var(--text-muted)]">
              «{order.notes}»
            </p>
          )}

          <div className="mt-3 flex items-end justify-between border-t border-[var(--surface-border)] pt-3">
            <span className="font-medium text-[var(--text-strong)]">Total</span>
            <Money cents={order.totalCents} rate={order.exchangeRate} scale="lg" />
          </div>
        </section>
      </div>

      <footer className="mx-auto flex w-full max-w-2xl flex-col gap-3 px-4 pt-2 pb-6">
        {error != null && (
          <p role="status" className="text-center text-xs text-[var(--text-muted)]">
            {error}
          </p>
        )}

        {/* An order that gets stuck had no way out: the screen said where it was
            and nothing else. `tel:` and not WhatsApp because the phone number is
            free text and building a `wa.me` means guessing the country code. */}
        {shop.phone != null && (
          <a
            href={`tel:${shop.phone.replace(/\s/g, '')}`}
            className="flex min-h-touch items-center justify-center rounded-[var(--radius-md)] bg-[var(--surface-raised)] font-medium text-[var(--text-strong)]"
          >
            Llamar a {shop.name}
          </a>
        )}

        <Link
          to="/"
          className="text-center text-sm text-[var(--text-muted)] underline-offset-2 hover:underline"
        >
          Volver a la carta
        </Link>
      </footer>
    </div>
  )
}

/**
 * Where it has got to, with the CURRENT step distinct from the past ones.
 *
 * `steps[].done` arrives cumulative from the server, so the step in progress
 * came marked like the finished ones: the customer read "✓ We are making it"
 * with no way to tell whether it was pending or done. The current one is the
 * last `done`, derived here without asking the server for anything.
 *
 * And how long they have waited, which is THE question. There is no estimate of
 * how long is left, because the system does not know how long this kitchen
 * really takes today, and an invented "5 minutes" that is missed is worse than
 * saying nothing.
 */
function Progress({ order }: { order: TrackedOrder }) {
  /*
   * Finished is what the LAST step says, not that all of them are marked.
   *
   * Steps can arrive with gaps: `ready` is stamped with `ready_at`, and a
   * counter sale handed over directly never passes through it. They arrive as
   * [done, done, NO, done] — and with the naive "the last done is the current
   * one", a delivered order was painted as still in progress.
   */
  const finished = order.steps[order.steps.length - 1]?.done === true

  /*
   * And while it is on the way, how far it really got: the steps followed from
   * the start. A stray `done` after a gap is not a step reached, it is a stamp
   * applied early.
   */
  const advanceStep = order.steps.findIndex((step) => !step.done)
  const current = finished ? -1 : advanceStep - 1

  return (
    <section className="rounded-[var(--radius-lg)] bg-[var(--surface-raised)] p-4">
      <div className="mb-3 flex items-baseline justify-between gap-3">
        <h2 className="text-lg font-semibold text-accent-600">{order.statusLabel}</h2>
        <span className="shrink-0 text-sm text-[var(--text-muted)]">
          {waitedLabel(order.waitingSeconds)}
        </span>
      </div>

      <ol className="flex flex-col gap-3">
        {order.steps.map((step, i) => {
          const inProgress = i === current
          // Reached, not "marked": finished means all of them, otherwise only the
          // ones followed from the start.
          const done = (finished || i < advanceStep) && !inProgress

          return (
            <li key={step.key} className="flex items-center gap-3">
              <span
                aria-hidden="true"
                className={`grid size-7 shrink-0 place-items-center rounded-full text-sm ${
                  done
                    ? 'bg-ok-500 text-white'
                    : inProgress
                      ? 'bg-accent-600 text-white'
                      : 'bg-[var(--surface-sunken)] text-[var(--text-muted)]'
                }`}
              >
                {done ? '✓' : inProgress ? '●' : '·'}
              </span>

              <span
                className={
                  inProgress
                    ? 'font-semibold text-[var(--text-strong)]'
                    : done
                      ? 'text-[var(--text-muted)]'
                      : 'text-[var(--text-muted)] opacity-60'
                }
              >
                {step.label}
              </span>

              {/* To a screen reader the colour does not exist. */}
              {inProgress && <span className="sr-only">— en esto vamos ahora</span>}
            </li>
          )
        })}
      </ol>
    </section>
  )
}

/**
 * How long before the order cancels itself.
 *
 * What was missing and cost the most: an order with no receipt has
 * `expires_at`, and a task cancels it every ten minutes. The customer saw no
 * clock at all — the order died in their hand with no warning.
 *
 * The seconds are counted by the SERVER (`expiresInSeconds`). Deriving them
 * from the date on the phone would give the wrong deadline exactly where it
 * hurts most.
 */
function PaymentDeadline({ seconds, minutes }: { seconds: number | null; minutes: number }) {
  if (seconds === null) {
    return (
      <Notice tone="warn">
        Tienes {minutes} minutos para mandar el comprobante. Después el pedido se cancela solo.
      </Notice>
    )
  }

  if (seconds <= 0) {
    // Neither "0 min" nor a negative number: it says what is going to happen.
    // The task that cancels runs every ten minutes, so it can still arrive.
    return (
      <Notice tone="bad">
        Se te pasó el plazo del pago. Este pedido se cancela en cualquier momento — si ya pagaste,
        manda el comprobante ya o llámanos.
      </Notice>
    )
  }

  const left = Math.ceil(seconds / 60)

  return (
    <Notice tone={seconds <= 300 ? 'bad' : 'warn'}>
      {left === 1 ? 'Te queda menos de un minuto' : `Te quedan ${left} minutos`} para mandar el
      comprobante. Después el pedido se cancela solo.
    </Notice>
  )
}

function Notice({ tone, children }: { tone: 'warn' | 'bad'; children: React.ReactNode }) {
  return (
    <p
      role="status"
      className={`rounded-[var(--radius-lg)] p-4 font-medium ${
        tone === 'bad' ? 'bg-bad-50 text-bad-700' : 'bg-warn-50 text-warn-700'
      }`}
    >
      {children}
    </p>
  )
}

/** "7 min ago", from the seconds the server counted. */
function waitedLabel(seconds: number): string {
  if (seconds < 60) return 'ahora mismo'

  const minutes = Math.floor(seconds / 60)
  if (minutes < 60) return `hace ${minutes} min`

  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `hace ${hours} h`

  return `hace ${Math.floor(hours / 24)} d`
}

/**
 * The mobile-payment photo.
 *
 * `capture` is deliberately absent: many people pay from the banking app and
 * keep the SCREENSHOT. Forcing the camera would make them photograph their own
 * screen with another phone.
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
  const [elegida, setElegida] = useState<string | null>(null)
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
    <section className="flex flex-col gap-3 rounded-[var(--radius-lg)] bg-[var(--surface-raised)] p-4">
      <h2 className="font-medium text-[var(--text-strong)]">Manda tu comprobante</h2>

      {shop.mobilePaymentDetails != null && (
        <p className="rounded-[var(--radius-md)] bg-[var(--surface-sunken)] p-3 text-sm whitespace-pre-line text-[var(--text-default)]">
          Paga a: {shop.mobilePaymentDetails}
        </p>
      )}

      {/*
       * The file picker sits in a `label` with the `input` hidden.
       *
       * The browser paints the native control in ITS language: on an
       * English-set phone it said "Choose File / No file chosen" in the middle
       * of a Spanish screen, right where somebody is trying to pay. It also
       * gets the touch height the rest of the screen has.
       */}
      <Field label="Foto del pago" required error={error ?? undefined}>
        {({ id, invalid }) => (
          <>
            <label
              htmlFor={id}
              className={`flex min-h-touch cursor-pointer items-center justify-center rounded-[var(--radius-md)] border border-dashed px-4 text-center font-medium ${
                invalid
                  ? 'border-bad-500 text-bad-700'
                  : 'border-[var(--surface-border)] text-[var(--text-strong)]'
              }`}
            >
              {elegida ?? 'Toca para elegir la foto'}
            </label>

            <input
              id={id}
              ref={fileInput}
              type="file"
              accept="image/*"
              className="sr-only"
              onChange={(e) => setElegida(e.target.files?.[0]?.name ?? null)}
            />
          </>
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
