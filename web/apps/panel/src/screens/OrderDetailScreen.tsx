import { ApiError } from '@kombo/api-client'
import {
  Badge,
  Button,
  Card,
  Field,
  Input,
  Money,
  Select,
  Spinner,
  formatUsd,
  parseAmount, Page
} from '@kombo/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useNavigate, useParams } from 'react-router'
import { catalog } from '../api/catalog'
import {
  channelLabel,
  nextStep,
  orders,
  paymentLabel,
  PAYMENT_METHODS,
  statusTone,
  waitedLabel,
} from '../api/orders'

/**
 * Un pedido, entero.
 *
 * Lo que lleva, lo que falta por cobrar, y las dos acciones que importan:
 * moverlo al siguiente paso, o cancelarlo con un motivo.
 */
export function OrderDetailScreen() {
  const { id = '' } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const [metodo, setMetodo] = useState<string>('cash_usd')
  const [monto, setMonto] = useState('')
  const [referencia, setReferencia] = useState('')
  const [motivo, setMotivo] = useState('')
  const [error, setError] = useState<string | null>(null)

  const rate = useQuery({ queryKey: ['rate'], queryFn: catalog.rate })
  const order = useQuery({ queryKey: ['order', id], queryFn: () => orders.one(id) })

  function refrescar(): void {
    void queryClient.invalidateQueries({ queryKey: ['order', id] })
    void queryClient.invalidateQueries({ queryKey: ['orders'] })
  }

  const avanzar = useMutation({
    mutationFn: (status: string) => orders.advance(id, status),
    onSuccess: refrescar,
    onError: (e: unknown) => setError(mensajeDe(e)),
  })

  const cobrar = useMutation({
    mutationFn: () => {
      const cents = parseAmount(monto)
      if (cents === null || cents <= 0) throw new Error('Escribe cuánto pagó, como 3,50.')

      return orders.pay(id, metodo, cents, referencia || undefined)
    },
    onSuccess: () => {
      setMonto('')
      setReferencia('')
      setError(null)
      refrescar()
    },
    onError: (e: unknown) => setError(mensajeDe(e)),
  })

  const confirmarPago = useMutation({
    mutationFn: (paymentId: string) => orders.confirmPayment(id, paymentId),
    onSuccess: refrescar,
  })

  const cancelar = useMutation({
    mutationFn: () => orders.cancel(id, motivo),
    onSuccess: () => {
      refrescar()
      void navigate('/pedidos')
    },
    onError: (e: unknown) => setError(mensajeDe(e)),
  })

  if (order.isLoading) return <Spinner />
  if (order.data == null) return null

  const pedido = order.data
  const paso = nextStep(pedido)

  return (
    <Page ancho="lectura" className="flex flex-col gap-4">
      <header>
        <div className="flex items-center gap-2">
          <h1 className="tabular text-2xl font-bold text-[var(--text-strong)]">#{pedido.number}</h1>
          {/* Con su tono, igual que el tablero y la ficha del cliente. El mismo
              estado saliendo gris aquí y ámbar allá obliga a leer la etiqueta
              para creerse el color, y entonces el color no sirve de nada. */}
          <Badge tone={statusTone(pedido.status)}>{pedido.statusLabel}</Badge>
        </div>
        <p className="text-sm text-[var(--text-muted)]">
          {pedido.serviceTypeLabel} · {channelLabel(pedido.channel)} ·{' '}
          {waitedLabel(pedido.waitingSeconds)}
          {pedido.customerName != null && ` · ${pedido.customerName}`}
        </p>
      </header>

      {/* Adónde va. Ésta es la pantalla desde la que se atiende un problema, y
          la dirección venía en la respuesta sin que nadie la pintara. */}
      {pedido.deliveryAddress != null && (
        <Card className="p-4">
          <p className="text-sm text-[var(--text-muted)]">Lo llevamos a</p>
          <p className="text-[var(--text-strong)]">{pedido.deliveryAddress}</p>
          {pedido.deliveryZoneName != null && (
            <p className="text-sm text-[var(--text-muted)]">{pedido.deliveryZoneName}</p>
          )}
          {pedido.customerPhone != null && (
            <a
              href={`tel:${pedido.customerPhone}`}
              className="tabular mt-1 inline-block font-medium text-accent-600"
            >
              {pedido.customerPhone}
            </a>
          )}
        </Card>
      )}

      {error != null && (
        <p role="alert" className="rounded-[var(--radius-md)] bg-bad-50 p-3 text-sm text-bad-700">
          {error}
        </p>
      )}

      <Card className="p-4">
        <ul className="flex flex-col gap-2">
          {pedido.items?.map((item) => (
            <li key={item.id} className="flex justify-between gap-3">
              <div className="min-w-0">
                <p className="text-[var(--text-strong)]">
                  <b className="tabular">{item.quantity}×</b> {item.name}
                </p>
                {/* Los agregados en línea propia y en ámbar: es justo lo que se
                    pasa por alto al leer rápido, y pasarlo por alto es rehacer
                    el plato. */}
                {item.modifiers.length > 0 && (
                  <p className="text-sm font-medium text-warn-700">
                    {item.modifiers.map((m) => m.name).join(' · ')}
                  </p>
                )}
                {item.notes != null && (
                  <p className="text-sm text-[var(--text-muted)]">«{item.notes}»</p>
                )}
              </div>
              <span className="tabular shrink-0 text-[var(--text-default)]">
                {formatUsd(item.lineTotalCents)}
              </span>
            </li>
          ))}
        </ul>

        <div className="mt-4 flex items-end justify-between border-t border-[var(--surface-hairline)] pt-3">
          <span className="text-sm text-[var(--text-muted)]">Total</span>
          <Money cents={pedido.totalCents} rate={rate.data?.rate ?? null} scale="md" />
        </div>

        {pedido.outstandingCents > 0 && (
          <p className="mt-1 text-right text-sm font-medium text-warn-700">
            Falta {formatUsd(pedido.outstandingCents)}
          </p>
        )}
      </Card>

      {paso !== null && (
        <Button size="touch" block disabled={avanzar.isPending} onClick={() => avanzar.mutate(paso.status)}>
          {paso.label}
        </Button>
      )}

      {pedido.isOpen && (
        <Card className="flex flex-col gap-3 p-4">
          <h2 className="font-semibold text-[var(--text-strong)]">Cobrar</h2>

          {/* Varios pagos por pedido: aquí se cobra mezclado —parte en efectivo
              y el resto en pago móvil— y con un solo método eso no se puede
              anotar. */}
          <div className="flex gap-2">
            <Select
              aria-label="Método de pago"
              value={metodo}
              onChange={(e) => setMetodo(e.target.value)}
            >
              {PAYMENT_METHODS.map((m) => (
                <option key={m.value} value={m.value}>
                  {m.label}
                </option>
              ))}
            </Select>
            <Input
              aria-label="Cuánto pagó"
              inputMode="decimal"
              placeholder="0,00"
              className="w-32"
              value={monto}
              onChange={(e) => setMonto(e.target.value)}
            />
          </div>

          <Field label="Referencia" hint="El número del pago móvil o de la transferencia.">
            {({ id: fieldId }) => (
              <Input
                id={fieldId}
                value={referencia}
                onChange={(e) => setReferencia(e.target.value)}
              />
            )}
          </Field>

          <Button variant="secondary" block disabled={cobrar.isPending} onClick={() => cobrar.mutate()}>
            Registrar el pago
          </Button>

          {(pedido.payments?.length ?? 0) > 0 && (
            <ul className="flex flex-col gap-2 border-t border-[var(--surface-hairline)] pt-3">
              {pedido.payments?.map((pago) => (
                <li key={pago.id} className="flex items-center justify-between gap-3">
                  <div className="min-w-0">
                    <p className="text-sm text-[var(--text-strong)]">
                      {paymentLabel(pago.method)} · {formatUsd(pago.amountCents)}
                    </p>
                    {pago.reference != null && (
                      <p className="text-xs text-[var(--text-muted)]">Ref. {pago.reference}</p>
                    )}

                    {/* La foto que mandó el cliente. Se abre aparte, y detrás
                        hay una ruta de la API que comprueba permiso y negocio
                        antes de servirla: un comprobante lleva la cédula y el
                        saldo de quien pagó. */}
                    {pago.hasReceipt && pago.receiptUrl != null && (
                      <a
                        href={pago.receiptUrl}
                        target="_blank"
                        rel="noreferrer"
                        className="text-xs font-medium text-accent-600 underline-offset-2 hover:underline"
                      >
                        Ver el comprobante
                      </a>
                    )}
                  </div>

                  {pago.status === 'confirmed' ? (
                    <Badge tone="ok">Confirmado</Badge>
                  ) : (
                    // El pago móvil lo confirma una persona mirando el
                    // comprobante. No hay API bancaria que preguntar.
                    <Button
                      size="sm"
                      variant="secondary"
                      disabled={confirmarPago.isPending}
                      onClick={() => confirmarPago.mutate(pago.id)}
                    >
                      Confirmar el pago
                    </Button>
                  )}
                </li>
              ))}
            </ul>
          )}
        </Card>
      )}

      {pedido.isOpen && (
        <Card className="flex flex-col gap-3 p-4">
          <h2 className="font-semibold text-[var(--text-strong)]">Cancelar</h2>

          {/* El motivo es obligatorio, y no es burocracia: cancelar es la vía
              natural para sacar comida sin cobrarla, y al final del mes alguien
              va a preguntar por qué hubo catorce. */}
          <Field label="Motivo" required>
            {({ id: fieldId }) => (
              <Input id={fieldId} value={motivo} onChange={(e) => setMotivo(e.target.value)} />
            )}
          </Field>

          <Button
            variant="danger"
            block
            disabled={cancelar.isPending || motivo.trim().length < 3}
            onClick={() => cancelar.mutate()}
          >
            Cancelar el pedido
          </Button>
        </Card>
      )}

      {pedido.cancellationReason != null && (
        <Card className="p-4">
          <p className="text-sm text-[var(--text-muted)]">Se canceló porque:</p>
          <p className="text-[var(--text-strong)]">«{pedido.cancellationReason}»</p>
        </Card>
      )}
    </Page>
  )
}

function mensajeDe(error: unknown): string {
  if (error instanceof ApiError) {
    const body = error.body

    if (typeof body === 'object' && body !== null && 'errors' in body) {
      const errors = (body as { errors: Record<string, string[]> }).errors
      const first = Object.values(errors)[0]?.[0]
      if (first) return first
    }

    return error.message
  }

  return error instanceof Error ? error.message : 'No se pudo completar.'
}
