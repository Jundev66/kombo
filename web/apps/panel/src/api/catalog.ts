import { api, type Paged } from '@kombo/api-client'

/**
 * Lo que el panel le pide a la carta.
 *
 * Los importes viajan y se guardan **en centavos**, siempre. Se formatean sólo
 * en el componente que los pinta: mandar «12,30» obligaría a re-parsearlo para
 * sumar, que es justo donde vuelven a aparecer los errores de coma flotante.
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
  /** La regla ya explicada por el servidor: «Elige una opción.» */
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
   * Una página de la carta, **con su `meta`**.
   *
   * Antes devolvía sólo `r.data` y el `meta` que ya mandaba el servidor se
   * perdía en esa línea. El efecto: un negocio con 693 productos veía 50 y no
   * había en la pantalla ni un número ni un botón que lo insinuara. Cortar en
   * silencio es el peor fallo que puede tener una lista, porque quien la mira
   * no sabe que le falta algo y por tanto no lo busca.
   */
  products: (params?: {
    category?: string
    buscar?: string
    incluirInactivos?: boolean
    page?: number
  }) => {
    const query = new URLSearchParams({ page: String(params?.page ?? 1) })
    if (params?.category) query.set('category', params.category)
    if (params?.buscar) query.set('buscar', params.buscar)
    if (params?.incluirInactivos) query.set('incluir_inactivos', '1')

    return api.get<Paged<Product>>(`/catalog/products?${query.toString()}`)
  },

  product: (id: string) => api.get<Envelope<Product>>(`/catalog/products/${id}`).then((r) => r.data),

  createProduct: (body: Record<string, unknown>) =>
    api.post<Envelope<Product>>('/catalog/products', body).then((r) => r.data),

  updateProduct: (id: string, body: Record<string, unknown>) =>
    api.patch<Envelope<Product>>(`/catalog/products/${id}`, body).then((r) => r.data),

  /**
   * El precio tiene su propia llamada, no un campo más del formulario.
   *
   * Es lo que hace real el permiso aparte: alguien puede tener `catalog.manage`
   * y no `catalog.change_price`, y para él este botón sencillamente no existe.
   */
  changePrice: (id: string, priceCents: number) =>
    api.post<Envelope<Product>>(`/catalog/products/${id}/price`, { price_cents: priceCents }).then((r) => r.data),

  /**
   * Sube la foto de un producto.
   *
   * Va como `FormData` y no como base64: una foto convertida a texto crece un
   * tercio, y ese tercio lo paga la conexión del local.
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
