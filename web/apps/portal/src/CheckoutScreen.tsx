import { ApiError } from '@kombo/api-client'
import { Button, EmptyState, Field, Input, Money, Select, Textarea, formatUsd } from '@kombo/ui'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { shopApi, type OrderPayload, type Shop } from './api'
import { lineTotalCents, type Cart } from './cart'

/**
 * El pedido y los datos, **en un solo scroll**.
 *
 * Sin pasos, sin «siguiente», sin barra de progreso: cada pantalla intermedia
 * es una oportunidad de abandonar, y aquí no hay nada tan complicado como para
 * repartirlo en tres. Se ve todo, se rellena de arriba abajo, y abajo del todo
 * hay un solo botón.
 */
export function CheckoutScreen({ shop, cart }: { shop: Shop; cart: Cart }) {
  const navigate = useNavigate()

  const [serviceType, setServiceType] = useState<'takeaway' | 'delivery'>('takeaway')
  const [zoneId, setZoneId] = useState('')
  const [address, setAddress] = useState('')
  const [name, setName] = useState('')
  const [phone, setPhone] = useState('')
  const [paymentMethod, setPaymentMethod] = useState<'cash' | 'pago_movil'>('cash')
  const [notes, setNotes] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [sending, setSending] = useState(false)

  if (cart.lines.length === 0) {
    return (
      <div className="min-h-dvh bg-[var(--surface-sunken)] pt-16">
        <EmptyState
          title="Tu pedido está vacío"
          description="Vuelve a la carta y elige algo."
          action={
            <Link to="/">
              <Button>Ver la carta</Button>
            </Link>
          }
        />
      </div>
    )
  }

  const zone = shop.zones.find((z) => z.id === zoneId)
  const deliveryFeeCents = serviceType === 'delivery' ? (zone?.feeCents ?? 0) : 0
  const totalCents = cart.subtotalCents + deliveryFeeCents

  const belowMinimum =
    serviceType === 'delivery' &&
    shop.minimumOrderCents > 0 &&
    cart.subtotalCents < shop.minimumOrderCents

  async function submit(): Promise<void> {
    setSending(true)
    setError(null)

    const payload: OrderPayload = {
      items: cart.toPayload(),
      service_type: serviceType,
      payment_method: paymentMethod,
      customer_name: name.trim(),
      customer_phone: phone.trim(),
      delivery_zone_id: serviceType === 'delivery' ? zoneId : null,
      delivery_address: serviceType === 'delivery' ? address.trim() : null,
      notes: notes.trim() === '' ? null : notes.trim(),
    }

    try {
      const order = await shopApi.place(payload)

      // El carrito se vacía sólo cuando el pedido ya existe de verdad. Si algo
      // falla, lo que eligió sigue ahí.
      cart.clear()

      // El enlace del pedido queda en el historial: es lo que le permite
      // volver después de irse a la aplicación del banco.
      void navigate(`/p/${order.token}`, { replace: true })
    } catch (failure) {
      setError(
        failure instanceof ApiError
          ? failure.message
          : 'No se pudo enviar el pedido. Inténtalo otra vez.',
      )
      setSending(false)
    }
  }

  const faltan = [
    name.trim().length < 2 ? 'tu nombre' : null,
    phone.trim().length < 7 ? 'tu teléfono' : null,
    serviceType === 'delivery' && zoneId === '' ? 'la zona' : null,
    serviceType === 'delivery' && address.trim() === '' ? 'la dirección' : null,
  ].filter((x): x is string => x !== null)

  return (
    // Un formulario de pedido a metro y medio no se rellena mejor: sólo
    // aleja cada etiqueta de su campo.
    <div className="mx-auto flex min-h-dvh w-full max-w-2xl flex-col bg-[var(--surface-sunken)] pb-28">
      <header className="flex items-center gap-3 bg-[var(--surface-raised)] px-4 py-4">
        <Link
          to="/"
          aria-label="Volver a la carta"
          className="min-h-11 text-2xl leading-none text-[var(--text-muted)]"
        >
          ‹
        </Link>
        <h1 className="text-lg font-bold text-[var(--text-strong)]">Tu pedido</h1>
      </header>

      <ul className="flex flex-col gap-2 p-4">
        {cart.lines.map((line) => (
          <li
            key={line.key}
            className="flex items-center gap-3 rounded-[var(--radius-md)] bg-[var(--surface-raised)] p-3"
          >
            <div className="min-w-0 flex-1">
              <p className="font-medium text-[var(--text-strong)]">{line.name}</p>

              {line.modifiers.length > 0 && (
                <p className="text-xs text-[var(--text-muted)]">
                  {line.modifiers.map((m) => m.name).join(' · ')}
                </p>
              )}
            </div>

            <div className="flex items-center gap-1">
              <button
                type="button"
                aria-label={`Uno menos de ${line.name}`}
                onClick={() => cart.setQuantity(line.key, line.quantity - 1)}
                className="size-11 rounded-[var(--radius-md)] bg-[var(--surface-sunken)] text-lg"
              >
                −
              </button>

              <span className="tabular w-7 text-center font-medium">{line.quantity}</span>

              <button
                type="button"
                aria-label={`Uno más de ${line.name}`}
                onClick={() => cart.setQuantity(line.key, line.quantity + 1)}
                className="size-11 rounded-[var(--radius-md)] bg-[var(--surface-sunken)] text-lg"
              >
                +
              </button>
            </div>

            <span className="tabular w-16 text-right text-sm">
              {formatUsd(lineTotalCents(line))}
            </span>
          </li>
        ))}
      </ul>

      <section className="flex flex-col gap-4 bg-[var(--surface-raised)] p-4">
        <fieldset>
          <legend className="mb-2 font-medium text-[var(--text-strong)]">¿Cómo lo recibes?</legend>

          <div className="flex gap-2">
            {shop.serviceTypes.includes('takeaway') && (
              <Choice
                active={serviceType === 'takeaway'}
                onClick={() => setServiceType('takeaway')}
              >
                Lo busco
              </Choice>
            )}

            {shop.serviceTypes.includes('delivery') && (
              <Choice
                active={serviceType === 'delivery'}
                onClick={() => setServiceType('delivery')}
              >
                Me lo traen
              </Choice>
            )}
          </div>
        </fieldset>

        {serviceType === 'delivery' && (
          <>
            <Field label="¿A qué zona?" required>
              {({ id }) => (
                <Select id={id} value={zoneId} onChange={(e) => setZoneId(e.target.value)}>
                  <option value="">Elige tu zona</option>
                  {shop.zones.map((z) => (
                    <option key={z.id} value={z.id}>
                      {z.name} · {formatUsd(z.feeCents)}
                      {z.estimatedMinutes != null && ` · ${z.estimatedMinutes} min`}
                    </option>
                  ))}
                </Select>
              )}
            </Field>

            <Field label="Dirección" hint="Calle, edificio, piso y una referencia." required>
              {({ id }) => (
                <Textarea id={id} value={address} onChange={(e) => setAddress(e.target.value)} />
              )}
            </Field>
          </>
        )}

        <Field label="¿Cómo te llamas?" required>
          {({ id }) => <Input id={id} value={name} onChange={(e) => setName(e.target.value)} />}
        </Field>

        <Field label="Teléfono" hint="Para avisarte cuando esté listo." required>
          {({ id }) => (
            <Input
              id={id}
              type="tel"
              inputMode="tel"
              value={phone}
              onChange={(e) => setPhone(e.target.value)}
            />
          )}
        </Field>

        <fieldset>
          <legend className="mb-2 font-medium text-[var(--text-strong)]">¿Cómo pagas?</legend>

          <div className="flex gap-2">
            {shop.paymentMethods.includes('cash') && (
              <Choice active={paymentMethod === 'cash'} onClick={() => setPaymentMethod('cash')}>
                Efectivo al recibir
              </Choice>
            )}

            {shop.paymentMethods.includes('pago_movil') && (
              <Choice
                active={paymentMethod === 'pago_movil'}
                onClick={() => setPaymentMethod('pago_movil')}
              >
                Pago móvil
              </Choice>
            )}
          </div>

          {paymentMethod === 'pago_movil' && shop.pagoMovilDetails != null && (
            <div className="mt-3 rounded-[var(--radius-md)] bg-[var(--surface-sunken)] p-3">
              <p className="text-sm font-medium text-[var(--text-strong)]">Paga a:</p>
              <p className="text-sm whitespace-pre-line text-[var(--text-default)]">
                {shop.pagoMovilDetails}
              </p>
              {/* Se dice ANTES de pedir, no después: el cliente tiene que saber
                  que va a tener que mandar la foto. */}
              <p className="mt-2 text-xs text-[var(--text-muted)]">
                Al confirmar te pedimos la foto del pago. Tienes{' '}
                {Math.round(shop.paymentWindowMinutes / 60)} h para enviarla.
              </p>
            </div>
          )}
        </fieldset>

        <Field label="¿Algo que debamos saber?" hint="Sin cebolla, tocar el timbre…">
          {({ id }) => (
            <Textarea id={id} value={notes} onChange={(e) => setNotes(e.target.value)} />
          )}
        </Field>
      </section>

      <div className="flex flex-col gap-1 p-4">
        <Row label="Productos" cents={cart.subtotalCents} rate={shop.exchangeRate} />

        {serviceType === 'delivery' && (
          <Row label="Reparto" cents={deliveryFeeCents} rate={shop.exchangeRate} />
        )}

        <div className="mt-2 flex items-end justify-between border-t border-[var(--surface-border)] pt-2">
          <span className="font-medium text-[var(--text-strong)]">Total</span>
          <Money cents={totalCents} rate={shop.exchangeRate} scale="lg" />
        </div>
      </div>

      <div className="fixed inset-x-0 bottom-0 border-t border-[var(--surface-border)] bg-[var(--surface-raised)] p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
        {error != null && (
          <p role="alert" className="mb-2 text-center text-sm font-medium text-bad-500">
            {error}
          </p>
        )}

        {!shop.isOpen && (
          <p role="status" className="mb-2 text-center text-sm font-medium text-bad-500">
            Ahora mismo está cerrado.
          </p>
        )}

        {belowMinimum && (
          <p role="status" className="mb-2 text-center text-sm text-[var(--text-muted)]">
            Para que te lo llevemos, el pedido tiene que llegar a{' '}
            {formatUsd(shop.minimumOrderCents)}.
          </p>
        )}

        <Button
          size="touch"
          block
          disabled={sending || !shop.isOpen || belowMinimum || faltan.length > 0}
          onClick={() => void submit()}
        >
          {/* El botón dice QUÉ FALTA, no sólo que no se puede. Un botón gris sin
              explicación deja a alguien mirando la pantalla sin saber qué
              tocar. */}
          {sending
            ? 'Enviando…'
            : faltan.length > 0
              ? `Falta ${faltan[0]}`
              : `Hacer el pedido · ${formatUsd(totalCents)}`}
        </Button>
      </div>
    </div>
  )
}

function Row({ label, cents, rate }: { label: string; cents: number; rate: number | null }) {
  return (
    <p className="flex justify-between text-sm">
      <span className="text-[var(--text-muted)]">{label}</span>
      <span className="tabular text-[var(--text-default)]">
        {formatUsd(cents)}
        {rate != null && rate > 0 && <span className="sr-only"> en dólares</span>}
      </span>
    </p>
  )
}

function Choice({
  active,
  onClick,
  children,
}: {
  active: boolean
  onClick: () => void
  children: string
}) {
  return (
    <button
      type="button"
      aria-pressed={active}
      onClick={onClick}
      className={`min-h-touch flex-1 rounded-[var(--radius-md)] border px-3 text-sm font-medium ${
        active
          ? 'border-accent-500 bg-accent-50 text-accent-700'
          : 'border-[var(--surface-border)] text-[var(--text-default)]'
      }`}
    >
      {children}
    </button>
  )
}
