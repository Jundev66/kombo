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
  /** Si el cliente mandó la foto de su pago móvil. */
  hasReceipt: boolean
  /**
   * Dónde pedirla. No es la ruta del archivo: es una dirección de la API que
   * comprueba permiso y negocio antes de servir la imagen.
   */
  receiptUrl: string | null
  status: 'pending_review' | 'confirmed' | 'rejected'
  confirmedAt: string | null
}

export interface Order {
  id: string
  number: number
  status: string
  /** Ya en palabras, resuelto por el servidor. */
  statusLabel: string
  isOpen: boolean
  isInKitchen: boolean
  serviceType: string
  serviceTypeLabel: string
  channel: string
  customerName: string | null
  customerPhone: string | null
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
  /** Lo calcula el SERVIDOR: el reloj de una tablet de local casi nunca está bien. */
  waitingSeconds: number
  items: OrderItem[] | null
  payments: OrderPayment[] | null
}

/** Los métodos de cobro, con el nombre que usa la gente. */
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
  /** Cuántos pedidos vivos no caben en el tablero. Si no es cero, se dice. */
  hidden: number
}

export const orders = {
  open: () => api.get<{ data: Order[]; meta: BoardMeta }>('/orders?abiertos=1'),

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
 * El siguiente paso, con el texto del botón.
 *
 * Vive en un solo sitio para que el tablero y el detalle no puedan discrepar
 * sobre qué se puede hacer con un pedido. El servidor valida lo mismo: esto es
 * para pintar, no para decidir.
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
      // Esperando pago, entregado o cancelado: no hay botón que ofrecer.
      return null
  }
}

/** «hace 7 min», a partir de lo que dijo el servidor. */
export function waitedLabel(seconds: number): string {
  if (seconds < 60) return 'ahora mismo'

  const minutes = Math.floor(seconds / 60)
  if (minutes < 60) return `hace ${minutes} min`

  const hours = Math.floor(minutes / 60)
  return `hace ${hours} h`
}
