import { api } from '@kombo/api-client'

/**
 * The delivery zones.
 *
 * By zone with a fixed fee, not by distance: what makes a trip expensive is not
 * the kilometres but climbing a hill or having nowhere to park. The courier
 * already knows what each place costs; this just writes it down.
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

  /** Switches the zone off. It is not deleted: old orders went somewhere. */
  disable: (id: string) => api.delete(`/delivery/zones/${id}`),
}
