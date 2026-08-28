import { api } from '@kombo/api-client'

/**
 * Las zonas de reparto.
 *
 * Por zona con tarifa fija, no por distancia: lo que encarece un viaje no son
 * los kilómetros sino subir un cerro o que no haya dónde estacionar. El
 * repartidor ya sabe cuánto cuesta cada sitio; esto sólo lo deja escrito.
 */

export interface DeliveryZone {
  id: string
  name: string
  feeCents: number
  estimatedMinutes: number | null
  isActive: boolean
  sortOrder: number
}

export const delivery = {
  zones: (incluirInactivas = false) =>
    api
      .get<{ data: DeliveryZone[] }>(
        `/delivery/zones${incluirInactivas ? '?incluir_inactivas=1' : ''}`,
      )
      .then((r) => r.data),

  create: (body: { name: string; fee_cents: number; estimated_minutes: number | null }) =>
    api.post<{ data: DeliveryZone }>('/delivery/zones', body).then((r) => r.data),

  update: (id: string, body: Record<string, unknown>) =>
    api.patch<{ data: DeliveryZone }>(`/delivery/zones/${id}`, body).then((r) => r.data),

  /** Apaga la zona. No la borra: los pedidos viejos fueron a algún sitio. */
  disable: (id: string) => api.delete(`/delivery/zones/${id}`),
}
