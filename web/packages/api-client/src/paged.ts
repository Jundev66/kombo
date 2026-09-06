/**
 * A list that does not fit whole.
 *
 * The server paginates in three places — the menu, the customers and the
 * platform's tenants — always in the same shape. It lives here in the contract
 * rather than repeated in each `api/*.ts`: when every screen described its own
 * `meta`, two of the three forgot to read it and truncated silently.
 *
 * What each one truncated, and why it paginates: KMB-0009.
 */
export interface PageMeta {
  page: number
  lastPage: number
  /** How many there are IN TOTAL, not how many fit. This is the number shown. */
  total: number
}

export interface Paged<T> {
  data: T[]
  meta: PageMeta
}

/** Is there another page to ask for? */
export function hasMore(meta: PageMeta): boolean {
  return meta.page < meta.lastPage
}
