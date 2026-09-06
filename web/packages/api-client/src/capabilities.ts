/**
 * The contract of `GET /api/v1/me`.
 *
 * The hub of the system: the server combines plan × enabled modules × settings
 * × permissions and returns the resolved result. The frontend paints menu,
 * routes and buttons from this and decides nothing.
 */

/**
 * `string` and NOT a union of literals (`'orders' | 'kitchen' | …`).
 *
 * The union would feel safer and would be exactly the mistake: it would put a
 * list only the server knows, and that changes without deploying, into the
 * client. A new module would stop the frontend compiling instead of simply
 * appearing.
 */
export type ModuleCode = string
export type PermissionCode = string

export interface TenantSummary {
  id: string
  name: string
  slug: string
  logoUrl: string | null
  status: string
  /**
   * The tenant's timezone, to date their data in their own time.
   *
   * A container in UTC — or the browser of an owner abroad — shifts order dates
   * in a way that looks correct until late in the day.
   */
  timezone: string
  /** Overdue or suspended: they have to be told before something stops working. */
  needsAttention: boolean
  canWrite: boolean
}

export interface UserSummary {
  id: string
  name: string
  email: string
  isOwner: boolean
  /**
   * "Owner", "Manager". `null` if nobody has assigned a role yet.
   *
   * Shown next to the name because the same person signs in from different
   * places, and knowing WHICH permissions you are looking with is the difference
   * between "this cannot be done" and "you cannot do this".
   */
  roleName: string | null
  /**
   * Which actions will ask for a supervisor's PIN.
   *
   * The till needs it to open the dialog BEFORE attempting the action, rather
   * than after the server rejects it with a customer waiting.
   */
  needsAuthorization: PermissionCode[]
}

export interface PlanLimits {
  /** `null` is UNLIMITED, never zero. */
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
  /** `null` = nobody has signed in yet → login screen. */
  user: UserSummary | null
  moduleNames: Record<ModuleCode, string>
  demo: boolean
  modules: ModuleCode[]
  permissions: PermissionCode[]
  /** Qualified `module.option` keys, with the effective, already-cast value. */
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
 * THE check: module enabled AND permission granted.
 *
 * A permission of a disabled module never grants access, however the role
 * reads. The server applies the same rule, so screen and API cannot disagree.
 */
export function allows(caps: Capabilities, module: ModuleCode, permission: PermissionCode): boolean {
  return hasModule(caps, module) && can(caps, permission)
}

export function setting<T>(caps: Capabilities, key: string, fallback: T): T {
  return (caps.settings[key] as T | undefined) ?? fallback
}
