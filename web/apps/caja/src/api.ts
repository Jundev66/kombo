import { api, useBearerToken } from '@kombo/api-client'
import { terminal, type Staff } from '@kombo/shell'

/**
 * Lo que la caja le pide al servidor.
 *
 * **Ningún importe sale de aquí hacia el servidor.** Se mandan identificadores
 * y cantidades; el precio lo pone el catálogo. Lo que esta pantalla calcula es
 * sólo para que el cajero vea el total mientras arma el pedido — la cifra que
 * vale es la que vuelve.
 */

// El token cambia dentro de la misma sesión: primero es el de la máquina, y al
// poner el PIN pasa a ser el de la persona que está cobrando.
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
  /** La regla ya explicada por el servidor: «Elige una opción.» */
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

/** Los métodos de cobro, con el nombre que usa la gente. */
export const PAYMENT_METHODS = [
  { value: 'cash_usd', label: 'Efectivo $' },
  { value: 'cash_bs', label: 'Efectivo Bs' },
  { value: 'pago_movil', label: 'Pago móvil' },
  { value: 'card', label: 'Punto' },
  { value: 'transfer', label: 'Transferencia' },
  { value: 'zelle', label: 'Zelle' },
  { value: 'binance', label: 'Binance' },
] as const

/** Los que llevan referencia. Sin ella, cuadrar el banco es imposible. */
export const NEEDS_REFERENCE = ['pago_movil', 'transfer', 'zelle', 'binance']

export function paymentLabel(method: string): string {
  return PAYMENT_METHODS.find((m) => m.value === method)?.label ?? method
}

export const counter = {
  /**
   * La carta entera, siguiendo las páginas.
   *
   * El servidor pagina —cincuenta por defecto— y la caja necesita **todo**: un
   * producto que no está en la cuadrícula es un producto que no se puede
   * vender, y el cajero no tiene forma de saber que le falta. En una carta
   * normal esto es una sola petición.
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
   * Quién puede autorizar lo que el mostrador sólo puede solicitar.
   *
   * La misma lista de nombres de la puerta: se pide QUIÉN autoriza además del
   * PIN, porque buscar «algún usuario cuyo PIN coincida» multiplica por N la
   * superficie de adivinación.
   */
  staff: () => api.get<{ staff: Staff[] }>('/auth/staff').then((r) => r.staff),

  /** Cobrar. Una sola llamada: lo que se llevó y cómo pagó. */
  sell: (body: {
    items: SaleLine[]
    payments: SalePayment[]
    service_type: string
    customer_name?: string | null
  }) => api.post<{ data: Sale }>('/counter/sales', body).then((r) => r.data),

  reprint: (noteId: string) =>
    api.post<{ data: DeliveryNote }>(`/notes/${noteId}/reprint`).then((r) => r.data),

  /**
   * Anular la venta entera: se cancela el pedido y se anula su nota.
   *
   * `authorized_by` y `authorization_pin` viajan sólo cuando quien está en la
   * caja no puede hacerlo solo. El servidor responde 422 sobre
   * `authorization_pin` si hacen falta y no llegaron.
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
