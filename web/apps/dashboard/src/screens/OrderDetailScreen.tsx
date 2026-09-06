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
 * One order, whole: what it carries, what is still owed, and the two actions
 * that matter — moving it a step, or cancelling it with a reason.
 */
export function OrderDetailScreen() {
  const { id = '' } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const [method, setMethod] = useState<string>('cash_usd')
  const [monto, setMonto] = useState('')
  const [reference, setReferencia] = useState('')
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)

  const rate = useQuery({ queryKey: ['rate'], queryFn: catalog.rate })
  const query = useQuery({ queryKey: ['order', id], queryFn: () => orders.one(id) })

  function refresh(): void {
    void queryClient.invalidateQueries({ queryKey: ['order', id] })
    void queryClient.invalidateQueries({ queryKey: ['orders'] })
  }

  const advance = useMutation({
    mutationFn: (status: string) => orders.advance(id, status),
    onSuccess: refresh,
    onError: (e: unknown) => setError(messageFrom(e)),
  })

  const charge = useMutation({
    mutationFn: () => {
      const cents = parseAmount(monto)
      if (cents === null || cents <= 0) throw new Error('Escribe cuánto pagó, como 3,50.')

      return orders.pay(id, method, cents, reference || undefined)
    },
    onSuccess: () => {
      setMonto('')
      setReferencia('')
      setError(null)
      refresh()
    },
    onError: (e: unknown) => setError(messageFrom(e)),
  })

  const confirmPayment = useMutation({
    mutationFn: (paymentId: string) => orders.confirmPayment(id, paymentId),
    onSuccess: refresh,
  })

  const cancel = useMutation({
    mutationFn: () => orders.cancel(id, reason),
    onSuccess: () => {
      refresh()
      void navigate('/pedidos')
    },
    onError: (e: unknown) => setError(messageFrom(e)),
  })

  if (query.isLoading) return <Spinner />
  if (query.data == null) return null

  const order = query.data
  const step = nextStep(order)

  return (
    <Page width="reading" className="flex flex-col gap-4">
      <header>
        <div className="flex items-center gap-2">
          <h1 className="tabular text-2xl font-bold text-[var(--text-strong)]">#{order.number}</h1>
          {/* With its tone, like the board and the customer record. The same state
              grey here and amber there means reading the label to believe the
              colour, and then the colour is useless. */}
          <Badge tone={statusTone(order.status)}>{order.statusLabel}</Badge>
        </div>
        <p className="text-sm text-[var(--text-muted)]">
          {order.serviceTypeLabel} · {channelLabel(order.channel)} ·{' '}
          {waitedLabel(order.waitingSeconds)}
          {order.customerName != null && ` · ${order.customerName}`}
        </p>
      </header>

      {/* Where it is going. This is the screen a problem gets handled from, and
          the address came in the response with nobody painting it. */}
      {order.deliveryAddress != null && (
        <Card className="p-4">
          <p className="text-sm text-[var(--text-muted)]">Lo llevamos a</p>
          <p className="text-[var(--text-strong)]">{order.deliveryAddress}</p>
          {order.deliveryZoneName != null && (
            <p className="text-sm text-[var(--text-muted)]">{order.deliveryZoneName}</p>
          )}
          {order.customerPhone != null && (
            <a
              href={`tel:${order.customerPhone}`}
              className="tabular mt-1 inline-block font-medium text-accent-600"
            >
              {order.customerPhone}
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
          {order.items?.map((item) => (
            <li key={item.id} className="flex justify-between gap-3">
              <div className="min-w-0">
                <p className="text-[var(--text-strong)]">
                  <b className="tabular">{item.quantity}×</b> {item.name}
                </p>
                {/* Add-ons on their own line and in amber: exactly what gets skipped
                    when reading fast, and skipping it means remaking the dish. */}
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
          <Money cents={order.totalCents} rate={rate.data?.rate ?? null} scale="md" />
        </div>

        {order.outstandingCents > 0 && (
          <p className="mt-1 text-right text-sm font-medium text-warn-700">
            Falta {formatUsd(order.outstandingCents)}
          </p>
        )}
      </Card>

      {step !== null && (
        <Button size="touch" block disabled={advance.isPending} onClick={() => advance.mutate(step.status)}>
          {step.label}
        </Button>
      )}

      {order.isOpen && (
        <Card className="flex flex-col gap-3 p-4">
          <h2 className="font-semibold text-[var(--text-strong)]">Cobrar</h2>

          {/* Several payments per order: people pay in a mix here, and one method
              cannot record that. */}
          <div className="flex gap-2">
            <Select
              aria-label="Método de pago"
              value={method}
              onChange={(e) => setMethod(e.target.value)}
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
                value={reference}
                onChange={(e) => setReferencia(e.target.value)}
              />
            )}
          </Field>

          <Button variant="secondary" block disabled={charge.isPending} onClick={() => charge.mutate()}>
            Registrar el pago
          </Button>

          {(order.payments?.length ?? 0) > 0 && (
            <ul className="flex flex-col gap-2 border-t border-[var(--surface-hairline)] pt-3">
              {order.payments?.map((payment) => (
                <li key={payment.id} className="flex items-center justify-between gap-3">
                  <div className="min-w-0">
                    <p className="text-sm text-[var(--text-strong)]">
                      {paymentLabel(payment.method)} · {formatUsd(payment.amountCents)}
                    </p>
                    {payment.reference != null && (
                      <p className="text-xs text-[var(--text-muted)]">Ref. {payment.reference}</p>
                    )}

                    {/* The photo the customer sent. It opens separately, behind an API
                        route that checks permission and tenant first: a receipt
                        carries the payer's ID number and balance. */}
                    {payment.hasReceipt && payment.receiptUrl != null && (
                      <a
                        href={payment.receiptUrl}
                        target="_blank"
                        rel="noreferrer"
                        className="text-xs font-medium text-accent-600 underline-offset-2 hover:underline"
                      >
                        Ver el comprobante
                      </a>
                    )}
                  </div>

                  {payment.status === 'confirmed' ? (
                    <Badge tone="ok">Confirmado</Badge>
                  ) : (
                    // Mobile payment is confirmed by a person looking at the receipt. There is
                    // no banking API to ask.
                    <Button
                      size="sm"
                      variant="secondary"
                      disabled={confirmPayment.isPending}
                      onClick={() => confirmPayment.mutate(payment.id)}
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

      {order.isOpen && (
        <Card className="flex flex-col gap-3 p-4">
          <h2 className="font-semibold text-[var(--text-strong)]">Cancelar</h2>

          {/* The reason is required, and not as bureaucracy: cancelling is the
              natural way to get food out unpaid, and at month end somebody will
              ask why there were fourteen. */}
          <Field label="Motivo" required>
            {({ id: fieldId }) => (
              <Input id={fieldId} value={reason} onChange={(e) => setReason(e.target.value)} />
            )}
          </Field>

          <Button
            variant="danger"
            block
            disabled={cancel.isPending || reason.trim().length < 3}
            onClick={() => cancel.mutate()}
          >
            Cancelar el pedido
          </Button>
        </Card>
      )}

      {order.cancellationReason != null && (
        <Card className="p-4">
          <p className="text-sm text-[var(--text-muted)]">Se canceló porque:</p>
          <p className="text-[var(--text-strong)]">«{order.cancellationReason}»</p>
        </Card>
      )}
    </Page>
  )
}

function messageFrom(error: unknown): string {
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
