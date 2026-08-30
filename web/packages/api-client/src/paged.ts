/**
 * Una lista que no cabe entera.
 *
 * El servidor pagina en tres sitios —la carta, los clientes y los negocios de
 * la super administración— y siempre con la misma forma. Está aquí, en el
 * contrato, y no repetida en cada `api/*.ts`: cuando cada pantalla se
 * describía su propio `meta`, dos de las tres se olvidaban de leerlo y
 * cortaban la lista sin decir nada.
 *
 * Qué cortaba cada una, y por qué se pagina en vez de traerlo todo: KMB-0009.
 */
export interface PageMeta {
  page: number
  lastPage: number
  /** Cuántas hay EN TOTAL, no cuántas caben. Es el número que se enseña. */
  total: number
}

export interface Paged<T> {
  data: T[]
  meta: PageMeta
}

/** ¿Queda otra página por pedir? */
export function hasMore(meta: PageMeta): boolean {
  return meta.page < meta.lastPage
}
