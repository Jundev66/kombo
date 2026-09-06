import { api } from '@kombo/api-client'

/**
 * The deliveries, for whoever makes them.
 *
 * Two lists and nothing else: what is ready to go out and what I am carrying. A
 * courier looks at this on the bike, one-handed.
 */

export interface Delivery {
  id: string
  number: number
  status: string
  statusLabel: string
  customerName: string | null
  /** What you call with when you cannot find the house. */
  customerPhone: string | null
  address: string | null
  zoneName: string | null
  totalCents: number
  /** What to collect on arrival. Zero if already paid. */
  toCollectCents: number
  isMine: boolean
  courierName: string | null
  readyAt: string | null
}

export const deliveries = {
  list: () => api.get<{ data: Delivery[] }>('/delivery/orders').then((r) => r.data),

  take: (id: string) => api.post(`/delivery/orders/${id}/take`),

  release: (id: string) => api.post(`/delivery/orders/${id}/release`),

  advance: (id: string, status: 'out_for_delivery' | 'delivered') =>
    api.post(`/delivery/orders/${id}/advance`, { status }),
}
