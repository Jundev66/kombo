import { api, type Paged } from '@kombo/api-client'

/**
 * What the dashboard asks of the menu.
 *
 * Amounts travel and are stored IN CENTS, always, formatted only in the
 * component that paints them: sending "12.30" would force re-parsing to add up,
 * which is where floating-point errors come back.
 */

export interface Product {
  id: string
  name: string
  description: string | null
  photoUrl: string | null
  priceCents: number
  currency: string
  priceUpdatedAt: string | null
  categoryId: string | null
  prepMinutes: number | null
  isActive: boolean
  tracksStock: boolean
  stockQty: number | null
  isSoldOut: boolean
  sortOrder: number
  modifierGroupIds: string[] | null
}

export interface Category {
  id: string
  name: string
  sortOrder: number
  isActive: boolean
  productCount: number
}

export interface Modifier {
  id: string
  name: string
  priceDeltaCents: number
  isActive: boolean
}

export interface ModifierGroup {
  id: string
  name: string
  minChoices: number
  maxChoices: number
  /** The rule already explained by the server: "Pick one option." */
  rule: string
  isActive: boolean
  modifiers: Modifier[]
}

export interface ExchangeRate {
  rate: number
  source: string
  effectiveDate: string
  isToday: boolean
}

interface Envelope<T> {
  data: T
}

export const catalog = {
  /**
   * A page of the menu, WITH its `meta`.
   *
   * It used to return only `r.data`, losing the `meta` the server already sent.
   * A tenant with 693 products saw 50, with neither a number nor a button to
   * suggest it — and truncating silently is the worst failure a list can have.
   */
  products: (params?: {
    category?: string
    search?: string
    includeInactive?: boolean
    page?: number
  }) => {
    const query = new URLSearchParams({ page: String(params?.page ?? 1) })
    if (params?.category) query.set('category', params.category)
    if (params?.search) query.set('search', params.search)
    if (params?.includeInactive) query.set('include_inactive', '1')

    return api.get<Paged<Product>>(`/catalog/products?${query.toString()}`)
  },

  product: (id: string) => api.get<Envelope<Product>>(`/catalog/products/${id}`).then((r) => r.data),

  createProduct: (body: Record<string, unknown>) =>
    api.post<Envelope<Product>>('/catalog/products', body).then((r) => r.data),

  updateProduct: (id: string, body: Record<string, unknown>) =>
    api.patch<Envelope<Product>>(`/catalog/products/${id}`, body).then((r) => r.data),

  /**
   * The price has its own call, not one more field on the form.
   *
   * That is what makes the separate permission real: somebody can hold
   * `catalog.manage` and not `catalog.change_price`, and for them this button
   * simply does not exist.
   */
  changePrice: (id: string, priceCents: number) =>
    api.post<Envelope<Product>>(`/catalog/products/${id}/price`, { price_cents: priceCents }).then((r) => r.data),

  /**
   * Uploads a product's photo.
   *
   * As `FormData` rather than base64: a photo turned into text grows by a
   * third, and the shop's connection pays for that third.
   */
  uploadPhoto: async (id: string, file: File): Promise<string> => {
    const form = new FormData()
    form.append('photo', file)

    const xsrf = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1]

    const response = await fetch(`/api/v1/catalog/products/${id}/photo`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
      },
      body: form,
    })

    const parsed: unknown = await response.json()

    if (!response.ok) {
      throw new Error(
        typeof parsed === 'object' && parsed !== null && 'message' in parsed
          ? String((parsed as { message: unknown }).message)
          : 'No se pudo subir la foto.',
      )
    }

    return (parsed as { data: { photoUrl: string } }).data.photoUrl
  },

  removePhoto: (id: string) => api.delete(`/catalog/products/${id}/photo`),

  categories: () => api.get<{ data: Category[] }>('/catalog/categories').then((r) => r.data),

  createCategory: (name: string) => api.post('/catalog/categories', { name }),

  deleteCategory: (id: string) => api.delete(`/catalog/categories/${id}`),

  modifierGroups: () =>
    api.get<{ data: ModifierGroup[] }>('/catalog/modifier-groups').then((r) => r.data),

  createModifierGroup: (body: Record<string, unknown>) => api.post('/catalog/modifier-groups', body),

  deleteModifierGroup: (id: string) => api.delete(`/catalog/modifier-groups/${id}`),

  rate: () => api.get<{ data: ExchangeRate | null }>('/exchange-rate').then((r) => r.data),

  setRate: (rate: number) => api.post('/exchange-rate', { rate }),
}
