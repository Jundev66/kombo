/**
 * The system's addresses.
 *
 * The subdomain IS the tenant: there is no tenant parameter in any URL and no
 * header to set from the client. A test that needs another tenant changes host,
 * not parameter.
 */

const PORT = process.env.E2E_PORT ?? '8010'

/** The demo tenants. Seeded with `php artisan demo:reset`. */
export const TENANTS = {
  /** Arepera: the full case — till, kitchen, portal and delivery. */
  arepera: 'elsazon',
  /** Pizzeria: portal and bots only. Proves the till can be switched off. */
  pizzeria: 'laesquina',
} as const

/** Every seeded user shares a password. It is a demo. */
export const PASSWORD = 'demo1234'

function origin(host: string): string {
  return PORT === '80' ? `http://${host}` : `http://${host}:${PORT}`
}

export function addressOf(tenant: string, path = '/'): string {
  return origin(`${tenant}.localhost`) + path
}

export const portalOf = (tenant: string) => addressOf(tenant, '/')
export const posOf = (tenant: string) => addressOf(tenant, '/pos/')
export const dashboardOf = (tenant: string) => addressOf(tenant, '/dashboard/')
export const kdsOf = (tenant: string) => addressOf(tenant, '/kds/')

/** Platform admin is not a tenant: `admin` is a reserved slug. */
export const adminAddress = (path = '/') => origin('admin.localhost') + path
