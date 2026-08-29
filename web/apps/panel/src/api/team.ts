import { api } from '@kombo/api-client'

/**
 * El equipo del negocio.
 *
 * La contraseña y el PIN **nunca vuelven**. La lista dice si alguien tiene PIN
 * puesto —sin él no entra a la caja ni a la cocina— y para cambiarlo se escribe
 * otro.
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
  /** `null` es ILIMITADO, nunca cero. */
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

  /** Da de baja: desactiva, no borra. */
  deactivate: (id: string) => api.delete(`/team/${id}`),
}
