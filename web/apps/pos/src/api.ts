import { api, useBearerToken } from '@kombo/api-client'
import { terminal, type Staff } from '@kombo/shell'

/**
 * What the till asks of the server.
 *
 * No amount leaves here for the server: ids and quantities are sent, and the
 * catalog sets the price. What this screen computes is only so the cashier sees
 * a running total — the figure that counts is the one that comes back.
 */

// The token changes within one session: first the machine's, then the person
// who is taking payment.
useBearerToken(() => terminal.active())

export interface Modifier {
  id: string
  name: string
  priceDeltaCents: number
  isActive: boolean
}

export interface ModifierGroup {
  id: string
  name: string
  minChoices: number
  maxChoices: number
  /** The rule already explained by the server: "Pick one option." */
  rule: string
  isActive: boolean
  modifiers: Modifier[]
}

export interface Product {
  id: string
  name: string
  photoUrl: string | null
  priceCents: number
  categoryId: string | null
  isActive: boolean
  isSoldOut: boolean
  sortOrder: number
  modifierGroupIds: string[] | null
}

export interface Category {
  id: string
  name: string
  sortOrder: number
  isActive: boolean
}

export interface DeliveryNote {
  id: string
  orderId: string
  reference: string
  title: string
  disclaimer: string
  issuedAt: string | null
  issuedByName: string | null
  customerName: string | null
  totalCents: number
  exchangeRate: number | null
  isVoided: boolean
  voidReason: string | null
  printedCount: number
  snapshot: NoteSnapshot
}

export interface NoteSnapshot {
  title: string
  disclaimer: string
  orderNumber: number
  issuedAt: string
  lines: {
    name: string
    quantity: number
    unitPriceCents: number
    lineTotalCents: number
    modifiers: { name: string; priceDeltaCents: number }[]
  }[]
  subtotalCents: number
  deliveryFeeCents: number
  totalCents: number
  exchangeRate: number | null
  payments: { method: string; amountCents: number; reference: string | null }[]
}

export interface Sale {
  order: { id: string; number: number; totalCents: number; status: string }
  note: DeliveryNote
}

export interface SaleLine {
  product_id: string
  quantity: number
  modifier_ids: string[]
  notes?: string
}

export interface SalePayment {
  method: string
  amount_cents: number
  reference?: string | null
}

/** The payment methods, under the names people use. */
export const PAYMENT_METHODS = [
  { value: 'cash_usd', label: 'Efectivo $' },
  { value: 'cash_bs', label: 'Efectivo Bs' },
  { value: 'pago_movil', label: 'Pago móvil' },
  { value: 'card', label: 'Punto' },
  { value: 'transfer', label: 'Transferencia' },
  { value: 'zelle', label: 'Zelle' },
  { value: 'binance', label: 'Binance' },
] as const

/** The ones that carry a reference. Without it, reconciling the bank is impossible. */
export const NEEDS_REFERENCE = ['pago_movil', 'transfer', 'zelle', 'binance']

export function paymentLabel(method: string): string {
  return PAYMENT_METHODS.find((m) => m.value === method)?.label ?? method
}

export const counter = {
  /**
   * The whole menu, following the pages.
   *
   * The server paginates — fifty by default — and the till needs ALL of it: a
   * product missing from the grid is a product that cannot be sold, and the
   * cashier has no way to know it is missing. On a normal menu this is one
   * request.
   */
  products: async (): Promise<Product[]> => {
    const all: Product[] = []
    let page = 1
    let lastPage = 1

    do {
      const response = await api.get<{ data: Product[]; meta: { lastPage: number } }>(
        `/catalog/products?page=${page}`,
      )

      all.push(...response.data)
      lastPage = response.meta.lastPage
      page += 1
    } while (page <= lastPage)

    return all
  },

  categories: () =>
    api.get<{ data: Category[] }>('/catalog/categories').then((r) => r.data),

  modifierGroups: () =>
    api.get<{ data: ModifierGroup[] }>('/catalog/modifier-groups').then((r) => r.data),

  rate: () =>
    api
      .get<{ data: { rate: number } | null }>('/exchange-rate')
      .then((r) => r.data?.rate ?? null)
      .catch(() => null),

  /**
   * Who can authorise what the counter can only request.
   *
   * The same list of names as the gate: WHO authorises is asked for as well as
   * the PIN, because matching "any user whose PIN fits" multiplies the guessing
   * surface by N.
   */
  staff: () => api.get<{ staff: Staff[] }>('/auth/staff').then((r) => r.staff),

  /** Taking payment. One call: what they took and how they paid. */
  sell: (body: {
    items: SaleLine[]
    payments: SalePayment[]
    service_type: string
    customer_name?: string | null
  }) => api.post<{ data: Sale }>('/counter/sales', body).then((r) => r.data),

  reprint: (noteId: string) =>
    api.post<{ data: DeliveryNote }>(`/notes/${noteId}/reprint`).then((r) => r.data),

  /**
   * Voiding the whole sale: the order is cancelled and its note voided.
   *
   * `authorized_by` and `authorization_pin` travel only when whoever is at the
   * till cannot do it alone. The server answers 422 on `authorization_pin` when
   * they are needed and did not arrive.
   */
  voidSale: (orderId: string, reason: string, authorizedBy?: { userId: string; pin: string }) =>
    api
      .post<{ data: { note: DeliveryNote | null } }>(`/counter/sales/${orderId}/void`, {
        reason,
        ...(authorizedBy
          ? { authorized_by: authorizedBy.userId, authorization_pin: authorizedBy.pin }
          : {}),
      })
      .then((r) => r.data),
}
