import { Button, Field, Input, Money, Spinner, formatUsd } from '@kombo/ui'
import { useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router'
import { shopApi, type Shop, type TrackedOrder } from './api'
import { ShopHeader } from './ShopHeader'

/**
 * Dónde va mi pedido.
 *
 * Esta pantalla es la razón de que exista el token público: sobrevive a que el
 * cliente cierre el navegador para ir a la aplicación del banco, apague el
 * teléfono, o vuelva mañana a ver qué pasó. Sin cuenta y sin contraseña.
 *
 * Se refresca **cada 10 segundos**, y no cada 3: quien espera comida mira el
 * teléfono cada tanto, no fijamente, y cada consulta se paga en datos y en
 * batería de alguien que a lo mejor está en la calle. Ese mismo refresco es lo
 * que mantiene al día la cuenta atrás del pago, sin un temporizador de un
 * segundo que no compra nada y sí gasta.
 *
 * El orden de la pantalla responde a las preguntas en el orden en que se
 * hacen: qué tengo que hacer YO (el comprobante), por dónde va, adónde va, y
 * cuánto fue.
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
    // La pantalla se llena: el contenido crece y el pie se ancla abajo. Antes
    // todo quedaba apelotonado arriba con medio teléfono en gris debajo.
    <div className="flex min-h-dvh flex-col bg-[var(--surface-sunken)]">
      <ShopHeader
        shop={shop}
        // El encabezado de ESTA página es el pedido, no la marca: es el número
        // que el cliente lee en voz alta cuando llama a preguntar.
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

      {/* Con tope: una columna de seguimiento a metro y medio no se lee
          mejor, sólo separa el estado del importe. */}
      <div className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-4 p-4">
        {/* Lo primero es lo que tiene que hacer ÉL, no lo que estamos haciendo
            nosotros: mientras falte el comprobante, no hay pedido que seguir. */}
        {order.needsReceipt && (
          <PaymentDeadline seconds={order.expiresInSeconds} minutes={shop.paymentWindowMinutes} />
        )}

        {cancelado ? (
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

        {/* Adónde va. El cliente es el único que puede ver que la dirección
            está mal, y una entrega a la casa equivocada la paga el negocio. */}
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

        {/* Un pedido que se atasca no tenía salida: la pantalla decía por
            dónde iba y nada más. `tel:` y no WhatsApp porque el teléfono es
            texto libre y armar un `wa.me` obliga a adivinar el código de país. */}
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
 * Por dónde va, con el paso ACTUAL distinto de los que ya pasaron.
 *
 * `steps[].done` viene acumulativo del servidor, así que el paso en curso
 * llegaba marcado igual que los terminados: el cliente leía «✓ Lo estamos
 * haciendo» y no podía saber si le faltaba o ya estaba. El actual es el último
 * `done`, y se deriva aquí sin pedirle nada al servidor.
 *
 * Y cuánto lleva esperando, que es LA pregunta. No hay estimación de cuánto
 * falta porque el sistema no sabe cuánto tarda de verdad esta cocina hoy, y un
 * «5 minutos» inventado que se incumple es peor que no decir nada.
 */
function Progress({ order }: { order: TrackedOrder }) {
  /*
   * Terminado lo dice el ÚLTIMO paso, no que estén todos.
   *
   * Los pasos pueden llegar con huecos: `ready` se marca con `ready_at`, y una
   * venta de mostrador que se entrega directamente nunca pasa por ahí. Llegan
   * entonces como [hecho, hecho, NO, hecho] — y con la regla ingenua «el
   * último hecho es el actual», un pedido ya entregado se pintaba como si
   * siguiéramos en ello, con un hueco en medio.
   *
   * Si está entregado, está entregado: pasó por todos aunque nadie apuntara la
   * hora de uno.
   */
  const terminado = order.steps[order.steps.length - 1]?.done === true

  /*
   * Y mientras va en camino, hasta dónde llegó de verdad: los pasos seguidos
   * desde el principio. Un `done` suelto detrás de un hueco no es un paso
   * alcanzado, es un sello que se puso antes de tiempo.
   */
  const avance = order.steps.findIndex((step) => !step.done)
  const actual = terminado ? -1 : avance - 1

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
          const enCurso = i === actual
          // Alcanzado, no «marcado»: terminado son todos, y si no, sólo los
          // seguidos desde el principio.
          const hecho = (terminado || i < avance) && !enCurso

          return (
            <li key={step.key} className="flex items-center gap-3">
              <span
                aria-hidden="true"
                className={`grid size-7 shrink-0 place-items-center rounded-full text-sm ${
                  hecho
                    ? 'bg-ok-500 text-white'
                    : enCurso
                      ? 'bg-accent-600 text-white'
                      : 'bg-[var(--surface-sunken)] text-[var(--text-muted)]'
                }`}
              >
                {hecho ? '✓' : enCurso ? '●' : '·'}
              </span>

              <span
                className={
                  enCurso
                    ? 'font-semibold text-[var(--text-strong)]'
                    : hecho
                      ? 'text-[var(--text-muted)]'
                      : 'text-[var(--text-muted)] opacity-60'
                }
              >
                {step.label}
              </span>

              {/* Para un lector de pantalla el color no existe. */}
              {enCurso && <span className="sr-only">— en esto vamos ahora</span>}
            </li>
          )
        })}
      </ol>
    </section>
  )
}

/**
 * Cuánto le queda antes de que el pedido se cancele solo.
 *
 * Es lo que faltaba y más caro salía: un pedido sin comprobante tiene
 * `expires_at`, y una tarea lo cancela cada diez minutos. El cliente no veía
 * ningún reloj — se le moría el pedido en la mano sin un aviso.
 *
 * Los segundos los cuenta el SERVIDOR (`expiresInSeconds`). Derivarlo de la
 * fecha en el teléfono daría un plazo equivocado justo donde más duele.
 */
function PaymentDeadline({ seconds, minutes }: { seconds: number | null; minutes: number }) {
  if (seconds === null) {
    return (
      <Aviso tono="warn">
        Tienes {minutes} minutos para mandar el comprobante. Después el pedido se cancela solo.
      </Aviso>
    )
  }

  if (seconds <= 0) {
    // Ni «0 min» ni un número negativo: se dice lo que va a pasar. La tarea
    // que lo cancela corre cada diez minutos, así que todavía puede llegar.
    return (
      <Aviso tono="bad">
        Se te pasó el plazo del pago. Este pedido se cancela en cualquier momento — si ya pagaste,
        manda el comprobante ya o llámanos.
      </Aviso>
    )
  }

  const quedan = Math.ceil(seconds / 60)

  return (
    <Aviso tono={seconds <= 300 ? 'bad' : 'warn'}>
      {quedan === 1 ? 'Te queda menos de un minuto' : `Te quedan ${quedan} minutos`} para mandar el
      comprobante. Después el pedido se cancela solo.
    </Aviso>
  )
}

function Aviso({ tono, children }: { tono: 'warn' | 'bad'; children: React.ReactNode }) {
  return (
    <p
      role="status"
      className={`rounded-[var(--radius-lg)] p-4 font-medium ${
        tono === 'bad' ? 'bg-bad-50 text-bad-700' : 'bg-warn-50 text-warn-700'
      }`}
    >
      {children}
    </p>
  )
}

/** «hace 7 min», con los segundos que contó el servidor. */
function waitedLabel(seconds: number): string {
  if (seconds < 60) return 'ahora mismo'

  const minutes = Math.floor(seconds / 60)
  if (minutes < 60) return `hace ${minutes} min`

  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `hace ${hours} h`

  return `hace ${Math.floor(hours / 24)} d`
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

      {shop.pagoMovilDetails != null && (
        <p className="rounded-[var(--radius-md)] bg-[var(--surface-sunken)] p-3 text-sm whitespace-pre-line text-[var(--text-default)]">
          Paga a: {shop.pagoMovilDetails}
        </p>
      )}

      {/*
       * El botón de elegir archivo va en un `label` y el `input` escondido.
       *
       * El control nativo lo pinta el navegador con SU idioma: en un teléfono
       * en inglés decía «Choose File / No file chosen» en mitad de una pantalla
       * en español, justo en el paso donde alguien está intentando pagar. Y de
       * paso se puede darle la altura táctil que el resto de la pantalla tiene.
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
