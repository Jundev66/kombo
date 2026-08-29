import { api } from '@kombo/api-client'

/**
 * Las entregas, para quien las lleva.
 *
 * Dos listas y nada más: lo que está listo para salir y lo que llevo yo. Un
 * repartidor mira esto en la moto, con una mano.
 */

export interface Delivery {
  id: string
  number: number
  status: string
  statusLabel: string
  customerName: string | null
  /** Con lo que se llama cuando no se encuentra la casa. */
  customerPhone: string | null
  address: string | null
  zoneName: string | null
  totalCents: number
  /** Lo que hay que cobrar al llegar. Cero si ya pagó. */
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
