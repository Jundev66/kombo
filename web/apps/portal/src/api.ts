import { api } from '@kombo/api-client'

/**
 * Lo que el portal le pide al negocio.
 *
 * **Sin sesión y sin token.** Quien está del otro lado es alguien de la calle
 * con un teléfono; el negocio sale del subdominio desde el que se cargó la
 * página, así que no hay nada que mandar ni nada que se pueda equivocar.
 *
 * Y ningún importe viaja hacia el servidor: se mandan identificadores y
 * cantidades. El total que vale es el que vuelve.
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
  pagoMovilDetails: string | null
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
   * La foto del pago móvil.
   *
   * Va como `FormData` y no como base64: una captura de pantalla convertida a
   * texto crece un tercio, y ese tercio se paga en datos del teléfono de
   * alguien que a lo mejor no tiene wifi.
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
