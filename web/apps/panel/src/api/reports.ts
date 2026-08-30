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

export const reports = {
  sales: (periodo: Period) =>
    api.get<{ data: SalesReport }>(`/reports/sales?periodo=${periodo}`).then((r) => r.data),

  /**
   * La dirección del archivo.
   *
   * Se navega a ella en vez de descargarla con `fetch`: así el navegador la
   * guarda como archivo con su nombre, y en un teléfono la abre con la
   * aplicación de hojas de cálculo que tenga. Bajarla a memoria para volver a
   * ofrecerla sería trabajo para el mismo resultado.
   */
  exportUrl: (periodo: Period) => `/api/v1/reports/export?periodo=${periodo}`,
}
