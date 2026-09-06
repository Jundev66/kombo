import { api } from '@kombo/api-client'

/**
 * The sales.
 *
 * All in one call: the owner opens this on their phone, often with poor signal,
 * and five requests are five chances to see half a screen.
 */

export type Period = 'today' | 'yesterday' | 'week' | 'month'

export interface SalesReport {
  from: string
  to: string
  summary: {
    orders: number
    soldCents: number
    collectedCents: number
    /** Sold but not yet collected. */
    outstandingCents: number
    averageTicketCents: number
    cancelled: number
  }
  byChannel: { channel: string; orders: number; totalCents: number }[]
  byProduct: { name: string; quantity: number; totalCents: number }[]
  /** All 24 hours ALWAYS, with zero where there was nothing. */
  byHour: { hour: number; orders: number; totalCents: number }[]
  byPaymentMethod: { method: string; count: number; totalCents: number }[]
}

export const PERIODS: { value: Period; label: string }[] = [
  { value: 'today', label: 'Hoy' },
  { value: 'yesterday', label: 'Ayer' },
  { value: 'week', label: 'Esta semana' },
  { value: 'month', label: 'Este mes' },
]

export const reports = {
  sales: (period: Period) =>
    api.get<{ data: SalesReport }>(`/reports/sales?period=${period}`).then((r) => r.data),

  /**
   * The file's address.
   *
   * Navigated to rather than fetched: that way the browser saves it as a file
   * with its name, and on a phone opens it with whatever spreadsheet app it
   * has. Pulling it into memory to offer it again would be work for the same
   * result.
   */
  exportUrl: (period: Period) => `/api/v1/reports/export?period=${period}`,
}
