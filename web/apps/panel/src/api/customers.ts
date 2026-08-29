import { api } from '@kombo/api-client'

/**
 * La libreta de clientes.
 *
 * Se llena sola con cada pedido que trae teléfono. Lo único que se escribe a
 * mano es la nota.
 */

export interface Customer {
  id: string
  name: string | null
  phone: string
  notes: string | null
  ordersCount: number
  spentCents: number
  lastOrderAt: string | null
}

export interface CustomerDetail extends Customer {
  orders: {
    id: string
    number: number
    status: string
    statusLabel: string
    channel: string
    totalCents: number
    placedAt: string | null
  }[]
}

export const customers = {
  list: (buscar = '') =>
    api
      .get<{ data: Customer[] }>(`/customers${buscar ? `?buscar=${encodeURIComponent(buscar)}` : ''}`)
      .then((r) => r.data),

  one: (id: string) => api.get<{ data: CustomerDetail }>(`/customers/${id}`).then((r) => r.data),

  saveNotes: (id: string, notes: string) => api.patch(`/customers/${id}`, { notes }),
}
