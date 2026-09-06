import { api } from '@kombo/api-client'

/**
 * What the portal asks of the tenant.
 *
 * No session and no token: the person on the other side is somebody off the
 * street with a phone, and the tenant comes from the subdomain the page loaded
 * from — nothing to send and nothing to get wrong.
 *
 * And no amount travels to the server: ids and quantities do. The total that
 * counts is the one that comes back.
 */

export interface Shop {
  name: string
  slug: string
  logoUrl: string | null
  brandColor: string | null
  phone: string | null
  address: string | null
  notice: string | null
  isOpen: boolean
  hours: { weekday: number; label: string; opensAt: string | null; closesAt: string | null; isClosed: boolean }[]
  timezone: string
  serviceTypes: string[]
  zones: { id: string; name: string; feeCents: number; estimatedMinutes: number | null }[]
  minimumOrderCents: number
  paymentMethods: string[]
  mobilePaymentDetails: string | null
  paymentWindowMinutes: number
  exchangeRate: number | null
}

export interface MenuProduct {
  id: string
  name: string
  description: string | null
  photoUrl: string | null
  priceCents: number
  categoryId: string | null
  prepMinutes: number | null
  modifierGroupIds: string[]
}

export interface MenuModifier {
  id: string
  name: string
  priceDeltaCents: number
}

export interface MenuModifierGroup {
  id: string
  name: string
  minChoices: number
  maxChoices: number
  modifiers: MenuModifier[]
}

export interface Menu {
  categories: { id: string; name: string }[]
  products: MenuProduct[]
  modifierGroups: MenuModifierGroup[]
}

export interface TrackedOrder {
  token: string
  number: number
  status: string
  statusLabel: string
  steps: { key: string; label: string; done: boolean }[]
  serviceType: string
  serviceTypeLabel: string
  customerName: string | null
  deliveryAddress: string | null
  deliveryZoneName: string | null
  subtotalCents: number
  deliveryFeeCents: number
  totalCents: number
  exchangeRate: number | null
  paymentStatus: string | null
  expiresAt: string | null
  needsReceipt: boolean
  /**
   * Both deadlines, in seconds and counted by the server.
   *
   * Deliberately not derived from `placedAt` / `expiresAt` on the client: a
   * phone's clock out in the street is wrong more often than you would think,
   * and `expiresInSeconds` is how long somebody has before their order cancels
   * itself. Never negative: zero means the deadline has passed.
   */
  waitingSeconds: number
  expiresInSeconds: number | null
  notes: string | null
  cancellationReason: string | null
  placedAt: string | null
  items: { name: string; quantity: number; lineTotalCents: number; modifiers: string[] }[]
}

export interface OrderPayload {
  items: { product_id: string; quantity: number; modifier_ids: string[]; notes?: string }[]
  service_type: 'takeaway' | 'delivery'
  payment_method: 'cash' | 'pago_movil'
  customer_name: string
  customer_phone: string
  delivery_zone_id?: string | null
  delivery_address?: string | null
  notes?: string | null
}

export const shopApi = {
  shop: () => api.get<{ data: Shop }>('/portal/shop').then((r) => r.data),

  menu: () => api.get<{ data: Menu }>('/portal/menu').then((r) => r.data),

  place: (body: OrderPayload) =>
    api.post<{ data: TrackedOrder }>('/portal/orders', body).then((r) => r.data),

  track: (token: string) =>
    api.get<{ data: TrackedOrder }>(`/portal/orders/${token}`).then((r) => r.data),

  /**
   * The mobile-payment photo.
   *
   * As `FormData` rather than base64: a screenshot turned into text grows by a
   * third, paid for in data by somebody who may have no wifi.
   */
  uploadReceipt: async (token: string, file: File, reference: string): Promise<TrackedOrder> => {
    const form = new FormData()
    form.append('receipt', file)

    if (reference.trim() !== '') {
      form.append('reference', reference.trim())
    }

    const response = await fetch(`/api/v1/portal/orders/${token}/receipt`, {
      method: 'POST',
      headers: { Accept: 'application/json' },
      body: form,
    })

    const parsed: unknown = await response.json()

    if (!response.ok) {
      const message =
        typeof parsed === 'object' && parsed !== null && 'message' in parsed
          ? String((parsed as { message: unknown }).message)
          : 'No se pudo enviar el comprobante.'

      throw new Error(message)
    }

    return (parsed as { data: TrackedOrder }).data
  },
}
