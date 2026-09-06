import { api } from '@kombo/api-client'

export interface OrderModifier {
  name: string
  priceDeltaCents: number
}

export interface OrderItem {
  id: string
  productId: string | null
  name: string
  quantity: number
  unitPriceCents: number
  lineTotalCents: number
  notes: string | null
  modifiers: OrderModifier[]
}

export interface OrderPayment {
  id: string
  method: string
  amountCents: number
  reference: string | null
  /** Whether the customer sent the photo of their mobile payment. */
  hasReceipt: boolean
  /**
   * Where to ask for it. Not the file path: an API address that checks
   * permission and tenant before serving the image.
   */
  receiptUrl: string | null
  status: 'pending_review' | 'confirmed' | 'rejected'
  confirmedAt: string | null
}

export interface Order {
  id: string
  number: number
  status: string
  /** Already in words, resolved by the server. */
  statusLabel: string
  isOpen: boolean
  isInKitchen: boolean
  serviceType: string
  serviceTypeLabel: string
  channel: string
  customerName: string | null
  customerPhone: string | null
  deliveryAddress: string | null
  deliveryZoneName: string | null
  subtotalCents: number
  deliveryFeeCents: number
  totalCents: number
  paidCents: number
  outstandingCents: number
  paymentStatus: string
  exchangeRate: number | null
  notes: string | null
  cancellationReason: string | null
  placedAt: string | null
  /** Computed by the SERVER: a shop tablet's clock is almost never right. */
  waitingSeconds: number
  items: OrderItem[] | null
  payments: OrderPayment[] | null
}

/** The payment methods, under the names people use. */
export const PAYMENT_METHODS = [
  { value: 'cash_usd', label: 'Efectivo en dólares' },
  { value: 'cash_bs', label: 'Efectivo en bolívares' },
  { value: 'pago_movil', label: 'Pago móvil' },
  { value: 'transfer', label: 'Transferencia' },
  { value: 'zelle', label: 'Zelle' },
  { value: 'card', label: 'Punto de venta' },
  { value: 'binance', label: 'Binance' },
] as const

export function paymentLabel(method: string): string {
  return PAYMENT_METHODS.find((m) => m.value === method)?.label ?? method
}

export interface BoardMeta {
  total: number
  /** How many live orders do not fit on the board. If not zero, it is said. */
  hidden: number
}

export const orders = {
  open: () => api.get<{ data: Order[]; meta: BoardMeta }>('/orders?open=1'),

  one: (id: string) => api.get<{ data: Order }>(`/orders/${id}`).then((r) => r.data),

  advance: (id: string, status: string) =>
    api.post<{ data: Order }>(`/orders/${id}/advance`, { status }).then((r) => r.data),

  cancel: (id: string, reason: string) =>
    api.post<{ data: Order }>(`/orders/${id}/cancel`, { reason }).then((r) => r.data),

  pay: (id: string, method: string, amountCents: number, reference?: string) =>
    api
      .post<{ data: Order }>(`/orders/${id}/payments`, {
        method,
        amount_cents: amountCents,
        reference: reference ?? null,
      })
      .then((r) => r.data),

  confirmPayment: (orderId: string, paymentId: string) =>
    api
      .post<{ data: Order }>(`/orders/${orderId}/payments/${paymentId}/confirm`)
      .then((r) => r.data),
}

/**
 * The next step, with the button's text.
 *
 * In one place so the board and the detail cannot disagree about what can be
 * done with an order. The server validates the same: this is for painting, not
 * for deciding.
 */
export function nextStep(order: Order): { status: string; label: string } | null {
  switch (order.status) {
    case 'placed':
      return { status: 'confirmed', label: 'Confirmar' }
    case 'confirmed':
      return { status: 'preparing', label: 'A la cocina' }
    case 'preparing':
      return { status: 'ready', label: 'Listo' }
    case 'ready':
      return order.serviceType === 'delivery'
        ? { status: 'out_for_delivery', label: 'Sale a repartir' }
        : { status: 'delivered', label: 'Entregado' }
    case 'out_for_delivery':
      return { status: 'delivered', label: 'Entregado' }
    default:
      // Awaiting payment, delivered or cancelled: no button to offer.
      return null
  }
}

/** "7 min ago", from what the server said. */
export function waitedLabel(seconds: number): string {
  if (seconds < 60) return 'ahora mismo'

  const minutes = Math.floor(seconds / 60)
  if (minutes < 60) return `hace ${minutes} min`

  const hours = Math.floor(minutes / 60)
  return `hace ${hours} h`
}

/**
 * What colour an order's status takes.
 *
 * An unconfirmed order is amber: the only state where the system is waiting on
 * a person, and an order forgotten for twenty minutes is a lost customer.
 *
 * Here rather than in the screen that introduced it, because the board and a
 * customer's history show the SAME states — "Confirmed" grey in one place and
 * green in another means reading the label twice to believe the colour.
 */
export function statusTone(status: string): 'neutral' | 'warn' | 'ok' | 'bad' {
  if (status === 'cancelled') return 'bad'
  if (status === 'placed' || status === 'pending_payment') return 'warn'
  if (status === 'ready' || status === 'delivered') return 'ok'

  return 'neutral'
}

/**
 * An order's date, in the TENANT's timezone.
 *
 * Not the browser's: an owner opening the dashboard abroad — or from a UTC
 * container — would see last night's order dated today, and until late in the
 * day everything looks correct, which is what makes it hard to see.
 */
export function orderDate(iso: string | null, timezone: string): string {
  if (iso === null) return '—'

  return new Intl.DateTimeFormat('es-VE', {
    day: 'numeric',
    month: 'short',
    hour: 'numeric',
    minute: '2-digit',
    timeZone: timezone,
  }).format(new Date(iso))
}

/**
 * Which door an order came in through, in Spanish.
 *
 * Here rather than in `reports`, where it used to live: the channel is a
 * property of the ORDER, and the sales summary is only one of the places that
 * shows it. Two tables is how "Mostrador" ends up called "Caja" on one screen
 * and not the other.
 *
 * With a fallback for a channel this frontend does not yet know: its code is
 * shown rather than a blank, so a new channel leaves no nameless row.
 */
const ORDER_CHANNEL_LABELS: Record<string, string> = {
  portal: 'Portal',
  counter: 'Mostrador',
  whatsapp: 'WhatsApp',
  telegram: 'Telegram',
}

export function channelLabel(channel: string): string {
  return ORDER_CHANNEL_LABELS[channel] ?? channel
}
