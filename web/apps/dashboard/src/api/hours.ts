import { api } from '@kombo/api-client'

/**
 * What time the tenant opens.
 *
 * Not decoration: the portal takes no order outside opening hours, and an
 * unconfigured day is closed — the safe failure. Without this screen, changing
 * the closing time meant somebody going in through the database.
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
