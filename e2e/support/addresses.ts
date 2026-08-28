/**
 * Las direcciones del sistema.
 *
 * El subdominio ES el negocio: no hay parámetro de tenant en ninguna URL, ni
 * cabecera que poner desde el cliente. Si una prueba necesita otro negocio,
 * cambia de host, no de parámetro.
 */

const PORT = process.env.E2E_PORT ?? '8010'

/** Los negocios de demostración. Se siembran con `php artisan demo:reset`. */
export const TENANTS = {
  /** Arepera: el caso completo — caja, cocina, portal y delivery. */
  arepera: 'elsazon',
  /** Pizzería: sólo portal y bots, sin caja. Prueba que la caja se puede apagar. */
  pizzeria: 'laesquina',
} as const

/** Todos los usuarios sembrados comparten contraseña. Es una demostración. */
export const PASSWORD = 'demo1234'

function origin(host: string): string {
  return PORT === '80' ? `http://${host}` : `http://${host}:${PORT}`
}

export function addressOf(tenant: string, path = '/'): string {
  return origin(`${tenant}.localhost`) + path
}

export const portalOf = (tenant: string) => addressOf(tenant, '/')
export const cajaOf = (tenant: string) => addressOf(tenant, '/caja/')
export const panelOf = (tenant: string) => addressOf(tenant, '/panel/')
export const cocinaOf = (tenant: string) => addressOf(tenant, '/cocina/')

/** La super administración no es un negocio: `admin` es un slug reservado. */
export const adminAddress = (path = '/') => origin('admin.localhost') + path
