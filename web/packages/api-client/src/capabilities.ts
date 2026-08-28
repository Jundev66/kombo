/**
 * El contrato de `GET /api/v1/me`.
 *
 * Es el eje del sistema: el servidor combina plan × módulos encendidos ×
 * configuración × permisos y devuelve el resultado ya resuelto. El frontend
 * pinta menú, rutas y botones a partir de esto y **no decide nada**.
 */

/**
 * `string` y NO una unión de literales (`'orders' | 'kitchen' | …`).
 *
 * Escribir la unión se sentiría más seguro y sería exactamente el error: metería
 * en el cliente una lista que sólo el servidor conoce y que cambia sin
 * desplegar. Un módulo nuevo dejaría de compilar el frontend en vez de,
 * sencillamente, aparecer.
 */
export type ModuleCode = string
export type PermissionCode = string

export interface TenantSummary {
  id: string
  name: string
  slug: string
  logoUrl: string | null
  status: string
  /** Vencido o suspendido: hay que decírselo antes de que algo deje de andar. */
  needsAttention: boolean
  canWrite: boolean
}

export interface UserSummary {
  id: string
  name: string
  email: string
  isOwner: boolean
  /**
   * Qué acciones le van a pedir el PIN de un supervisor.
   *
   * La caja lo necesita para abrir el diálogo ANTES de intentar la acción, en
   * vez de después de que el servidor la rechace con un cliente delante.
   */
  needsAuthorization: PermissionCode[]
}

export interface PlanLimits {
  /** `null` es ILIMITADO, nunca cero. */
  maxUsers: number | null
  maxProducts: number | null
  maxOrdersMonth: number | null
}

export interface UpgradeableModule {
  module: ModuleCode
  name: string
  requiresPlan: string
}

export interface Capabilities {
  tenant: TenantSummary | null
  /** `null` = nadie ha entrado todavía → pantalla de login. */
  user: UserSummary | null
  moduleNames: Record<ModuleCode, string>
  demo: boolean
  modules: ModuleCode[]
  permissions: PermissionCode[]
  /** Claves calificadas `modulo.opcion`, con el valor efectivo y ya casteado. */
  settings: Record<string, unknown>
  limits: PlanLimits
  upgradeable: UpgradeableModule[]
}

export function hasModule(caps: Capabilities, code: ModuleCode): boolean {
  return caps.modules.includes(code)
}

export function can(caps: Capabilities, permission: PermissionCode): boolean {
  return caps.permissions.includes(permission)
}

/**
 * LA comprobación: **módulo encendido Y permiso concedido**.
 *
 * Un permiso de un módulo apagado nunca concede acceso, aunque el rol lo tenga
 * escrito. El servidor aplica la misma regla, así que la pantalla y la API no
 * pueden discrepar.
 */
export function allows(caps: Capabilities, module: ModuleCode, permission: PermissionCode): boolean {
  return hasModule(caps, module) && can(caps, permission)
}

export function setting<T>(caps: Capabilities, key: string, fallback: T): T {
  return (caps.settings[key] as T | undefined) ?? fallback
}
