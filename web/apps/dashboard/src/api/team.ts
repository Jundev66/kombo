import { api } from '@kombo/api-client'

/**
 * The tenant's team.
 *
 * The password and the PIN never come back. The list says whether somebody has
 * a PIN set — without one they reach neither the till nor the kitchen — and to
 * change it you type another.
 */

export interface TeamMember {
  id: string
  name: string
  email: string
  isActive: boolean
  isOwner: boolean
  roleCode: string | null
  roleName: string | null
  hasPin: boolean
  lastLoginAt: string | null
}

export interface TeamMeta {
  active: number
  /** `null` is UNLIMITED, never zero. */
  maxUsers: number | null
  roles: { code: string; name: string }[]
}

export const team = {
  list: () => api.get<{ data: TeamMember[]; meta: TeamMeta }>('/team'),

  create: (body: {
    name: string
    email: string
    password: string
    role_code: string
    pin?: string | null
  }) => api.post<{ data: { id: string } }>('/team', body),

  update: (id: string, body: Record<string, unknown>) => api.patch(`/team/${id}`, body),

  /** Deactivates rather than deletes. */
  deactivate: (id: string) => api.delete(`/team/${id}`),
}
