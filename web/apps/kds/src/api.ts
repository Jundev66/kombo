import { api, useBearerToken } from '@kombo/api-client'
import { terminal } from './terminal'

// El token cambia dentro de la misma sesión de la pantalla: primero es el de
// la tablet, y al poner el PIN pasa a ser el del cocinero. Por eso se pasa una
// FUNCIÓN y no un valor.
useBearerToken(() => terminal.active())

export interface KitchenItem {
  id: string
  name: string
  quantity: number
  /** Ya en texto: «Sin cebolla». Listos para leer mientras se cocina. */
  modifiers: string[]
  notes: string | null
}

export interface Ticket {
  id: string
  number: number
  status: 'pending' | 'preparing' | 'ready'
  nextStatus: string | null
  /** Lo que dice el botón, resuelto por el servidor. */
  nextLabel: string | null
  serviceType: string | null
  /** Quién la tomó. Aparece en la tarjeta para saber a quién preguntar. */
  takenByName: string | null
  notes: string | null
  prepMinutes: number | null
  placedAt: string | null
  /** Lo calcula el SERVIDOR: el reloj de una tablet casi nunca está bien. */
  waitingSeconds: number
  items: KitchenItem[]
}

export interface Staff {
  id: string
  name: string
  roleName: string | null
}

export const kitchen = {
  tickets: () =>
    api.get<{ data: Ticket[]; meta: { staleMinutes: number } }>('/kitchen/tickets'),

  advance: (id: string, status: string) =>
    api.post<{ data: Ticket }>(`/kitchen/tickets/${id}/advance`, { status }),

  /** El nombre del negocio, para la puerta. `/me` responde sin sesión. */
  businessName: async (): Promise<string> => {
    try {
      const caps = await api.capabilities()

      return caps.tenant?.name ?? 'Cocina'
    } catch {
      return 'Cocina'
    }
  },

  provision: (email: string, password: string, device: string) =>
    api.post<{ token: string }>('/auth/device', { email, password, device }),

  staff: () => api.get<{ staff: Staff[] }>('/auth/staff'),

  pin: (userId: string, pin: string, device: string) =>
    api.post<{ token: string; user: { name: string } }>('/auth/pin', {
      user_id: userId,
      pin,
      device,
    }),
}
