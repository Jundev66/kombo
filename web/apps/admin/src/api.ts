import { api, type Paged } from '@kombo/api-client'

/**
 * What platform administration asks the server for.
 *
 * Another door and another session: being inside a tenant does not open this.
 * And it lives only at `admin.domain` — these routes do not even exist on a
 * customer's subdomain.
 */

export interface PlatformUser {
  id: string
  name: string
  email: string
}

export interface TenantRow {
  id: string
  name: string
  slug: string
  status: string
  statusLabel: string
  planCode: string
  /** The plan's name, which is what is shown. "Negocio", not `business`. */
  planName: string
  currentPeriodEnd: string | null
  /** Negative means days overdue. That is the figure people look at. */
  daysLeft: number | null
  createdAt: string
}

export interface Usage {
  used: number
  /** `null` is UNLIMITED, never zero. */
  max: number | null
}

export interface TenantDetail {
  id: string
  name: string
  slug: string
  status: string
  statusLabel: string
  createdAt: string
  subscription: {
    planCode: string
    status: string
    currentPeriodEnd: string
    graceDays: number
    suspendsAt: string
    daysLeft: number
  } | null
  usage: { users: Usage; products: Usage; ordersThisMonth: Usage }
  payments: {
    amountCents: number
    method: string
    reference: string | null
    paidAt: string
    periodTo: string
  }[]
  platformLog: { action: string; by: string | null; at: string }[]
}

export interface Metrics {
  tenants: { active: number; pastDue: number; suspended: number; newThisMonth: number }
  mrrCents: number
  collectedThisMonthCents: number
  ordersThisMonth: number
  expiringSoon: { name: string; slug: string; endsAt: string; daysLeft: number }[]
}

export interface Plan {
  code: string
  name: string
  description: string | null
  priceCents: number
  trialDays: number | null
  maxUsers: number | null
  maxProducts: number | null
  maxOrdersMonth: number | null
  modules: string[]
  tenants: number
}

export const PAYMENT_METHODS = [
  { value: 'pago_movil', label: 'Pago móvil' },
  { value: 'transfer', label: 'Transferencia' },
  { value: 'zelle', label: 'Zelle' },
  { value: 'cash', label: 'Efectivo' },
  { value: 'binance', label: 'Binance' },
] as const

export const platform = {
  me: () => api.get<{ data: PlatformUser | null }>('/platform/me').then((r) => r.data),

  login: async (email: string, password: string): Promise<PlatformUser> => {
    await api.csrf()

    const { data } = await api.post<{ data: PlatformUser }>('/platform/auth/login', {
      email,
      password,
    })

    return data
  },

  logout: () => api.post('/platform/auth/logout'),

  metrics: () => api.get<{ data: Metrics }>('/platform/metrics').then((r) => r.data),

  /** Returns the whole page with its `meta`: the list says how many there are. */
  tenants: (params?: { search?: string; status?: string; page?: number }) => {
    const query = new URLSearchParams({ page: String(params?.page ?? 1) })
    if (params?.search) query.set('search', params.search)
    if (params?.status) query.set('estado', params.status)

    return api.get<Paged<TenantRow>>(`/platform/tenants?${query.toString()}`)
  },

  tenant: (id: string) =>
    api.get<{ data: TenantDetail }>(`/platform/tenants/${id}`).then((r) => r.data),

  createTenant: (body: Record<string, unknown>) =>
    api.post<{ data: { tenant_id: string; slug: string } }>('/platform/tenants', body).then((r) => r.data),

  registerPayment: (
    id: string,
    body: { amount_cents: number; method: string; months: number; reference?: string | null },
  ) => api.post(`/platform/tenants/${id}/payments`, body),

  changeStatus: (id: string, status: string, reason?: string) =>
    api.post(`/platform/tenants/${id}/status`, { status, reason: reason ?? null }),

  changePlan: (id: string, planCode: string) =>
    api.post(`/platform/tenants/${id}/plan`, { plan_code: planCode }),

  support: (id: string) =>
    api
      .get<{
        data: {
          products: number
          team: { name: string; email: string; lastLoginAt: string | null }[]
          modules: string[]
          lastOrders: { number: number; status: string; channel: string; totalCents: number }[]
        }
      }>(`/platform/tenants/${id}/support`)
      .then((r) => r.data),

  plans: () =>
    api.get<{ data: Plan[]; meta: { availableModules: string[] } }>('/platform/plans'),
}
