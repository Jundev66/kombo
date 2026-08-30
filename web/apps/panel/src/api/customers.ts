import { api, type Paged } from '@kombo/api-client'

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
  /**
   * Devuelve la página ENTERA, con su `meta`.
   *
   * No sólo `r.data`: sin `meta.total` la pantalla no puede decir cuántos hay,
   * y una lista que corta sin decirlo es una lista en la que nadie sabe que le
   * falta algo.
   */
  list: (buscar = '', page = 1) => {
    const query = new URLSearchParams({ page: String(page) })
    if (buscar) query.set('buscar', buscar)

    return api.get<Paged<Customer>>(`/customers?${query.toString()}`)
  },

  one: (id: string) => api.get<{ data: CustomerDetail }>(`/customers/${id}`).then((r) => r.data),

  saveNotes: (id: string, notes: string) => api.patch(`/customers/${id}`, { notes }),
}
