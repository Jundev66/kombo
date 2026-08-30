// El contrato de GET /api/v1/me y el cliente HTTP.

export { allows, can, hasModule, setting } from './capabilities'
export type {
  Capabilities,
  ModuleCode,
  PermissionCode,
  PlanLimits,
  TenantSummary,
  UpgradeableModule,
  UserSummary,
} from './capabilities'
export { api, ApiError, useBearerToken } from './client'
export { hasMore } from './paged'
export type { Paged, PageMeta } from './paged'
