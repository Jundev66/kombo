/**
 * «1 producto», no «1 productos».
 *
 * Cuatro líneas para una falta de ortografía que salía en seis pantallas: la
 * lista de categorías, la de clientes, el resumen de ventas, la nota de
 * entrega, la cabecera de la cocina y la super administración.
 *
 * No parece grave y lo es: a un dueño que ve «1 productos» en la pantalla donde
 * lleva su negocio, eso le dice cuánto cuidado le pusimos al resto. Y cuesta
 * menos arreglarlo que discutirlo.
 *
 * Sin librería de internacionalización a propósito. El producto está en español
 * y sólo en español —así están escritos los mensajes de error del servidor, los
 * nombres de los estados y hasta los commits—, así que meter un motor de plurales
 * sería cargar con la abstracción de un problema que no tenemos.
 */
export function plural(count: number, singular: string, plural: string): string {
  return `${count} ${count === 1 ? singular : plural}`
}
