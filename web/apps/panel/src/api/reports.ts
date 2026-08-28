import { api } from '@kombo/api-client'

/**
 * Las ventas.
 *
 * Todo en una llamada: el dueño abre esto desde el teléfono, muchas veces con
 * mala señal, y cinco peticiones son cinco oportunidades de ver la pantalla a
 * medias.
 */

export type Period = 'hoy' | 'ayer' | 'semana' | 'mes'

export interface SalesReport {
  from: string
  to: string
  summary: {
    orders: number
    soldCents: number
    collectedCents: number
    /** Lo vendido que todavía no entró. */
    outstandingCents: number
    averageTicketCents: number
    cancelled: number
  }
  byChannel: { channel: string; orders: number; totalCents: number }[]
  byProduct: { name: string; quantity: number; totalCents: number }[]
  /** Las 24 horas SIEMPRE, con cero donde no hubo nada. */
  byHour: { hour: number; orders: number; totalCents: number }[]
  byPaymentMethod: { method: string; count: number; totalCents: number }[]
}

export const PERIODS: { value: Period; label: string }[] = [
  { value: 'hoy', label: 'Hoy' },
  { value: 'ayer', label: 'Ayer' },
  { value: 'semana', label: 'Esta semana' },
  { value: 'mes', label: 'Este mes' },
]

export const CHANNEL_LABELS: Record<string, string> = {
  counter: 'Mostrador',
  portal: 'Portal',
  whatsapp: 'WhatsApp',
  telegram: 'Telegram',
}

export const reports = {
  sales: (periodo: Period) =>
    api.get<{ data: SalesReport }>(`/reports/sales?periodo=${periodo}`).then((r) => r.data),
}
