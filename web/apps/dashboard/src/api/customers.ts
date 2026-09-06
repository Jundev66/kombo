import { api, type Paged } from '@kombo/api-client'

/**
 * The customer book. It fills itself in with every order that carries a phone
 * number; the only thing written by hand is the note.
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
   * Returns the WHOLE page, with its `meta`.
   *
   * Not just `r.data`: without `meta.total` the screen cannot say how many
   * there are, and a list that truncates without saying so is one where nobody
   * knows anything is missing.
   */
  list: (search = '', page = 1) => {
    const query = new URLSearchParams({ page: String(page) })
    if (search) query.set('search', search)

    return api.get<Paged<Customer>>(`/customers?${query.toString()}`)
  },

  one: (id: string) => api.get<{ data: CustomerDetail }>(`/customers/${id}`).then((r) => r.data),

  saveNotes: (id: string, notes: string) => api.patch(`/customers/${id}`, { notes }),
}
