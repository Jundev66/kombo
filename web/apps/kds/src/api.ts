import { api, useBearerToken } from '@kombo/api-client'
import { terminal } from '@kombo/shell'

// The token changes within one screen session: first the tablet's, then the
// cook's once they enter a PIN. Hence a FUNCTION rather than a value.
useBearerToken(() => terminal.active())

export interface KitchenItem {
  id: string
  name: string
  quantity: number
  /** Already text: "No onion". Ready to read while cooking. */
  modifiers: string[]
  notes: string | null
}

export interface Ticket {
  id: string
  number: number
  status: 'pending' | 'preparing' | 'ready'
  nextStatus: string | null
  /** What the button says, resolved by the server. */
  nextLabel: string | null
  serviceType: string | null
  /** Who took it. Shown on the card, so you know who to ask. */
  takenByName: string | null
  notes: string | null
  prepMinutes: number | null
  placedAt: string | null
  /** Computed by the SERVER: a tablet's clock is almost never right. */
  waitingSeconds: number
  items: KitchenItem[]
}

export const kitchen = {
  tickets: () =>
    api.get<{
      data: Ticket[]
      meta: {
        staleMinutes: number
        /** How many live tickets there are in total. */
        total: number
        /** How many do not fit on screen. If not zero, it has to be said. */
        hidden: number
      }
    }>('/kitchen/tickets'),

  advance: (id: string, status: string) =>
    api.post<{ data: Ticket }>(`/kitchen/tickets/${id}/advance`, { status }),

}
