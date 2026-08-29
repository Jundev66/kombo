import { api } from '@kombo/api-client'

/**
 * A qué hora abre el negocio.
 *
 * No es un adorno: **el portal no acepta un solo pedido fuera de horario**, y
 * un día sin configurar está cerrado —es el fallo seguro—. Sin esta pantalla,
 * cambiar la hora de cierre exigía que alguien entrara por la base de datos.
 */

export interface BusinessDay {
  weekday: number
  label: string
  opensAt: string | null
  closesAt: string | null
  isClosed: boolean
}

export const hours = {
  list: () => api.get<{ data: BusinessDay[] }>('/business-hours').then((r) => r.data),

  save: (days: { weekday: number; opens_at: string | null; closes_at: string | null; is_closed: boolean }[]) =>
    api.put<{ data: BusinessDay[] }>('/business-hours', { days }).then((r) => r.data),
}
