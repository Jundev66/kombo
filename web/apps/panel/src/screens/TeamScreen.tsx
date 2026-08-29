import { ApiError } from '@kombo/api-client'
import { Badge, Button, Card, Field, Input, Select, Spinner } from '@kombo/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { team, type TeamMember } from '../api/team'

/**
 * Quién trabaja aquí.
 *
 * Lo que hace falta para que un local crezca de una persona a cinco sin que
 * nadie toque la base de datos: sumar a alguien, darle un rol, ponerle un PIN,
 * y darlo de baja el día que se va.
 *
 * **El PIN se ve como sí/no, nunca como número.** Es lo que autoriza anular una
 * venta: enseñarlo en una pantalla que alguien puede estar mirando por encima
 * del hombro sería regalarlo.
 */
export function TeamScreen() {
  const queryClient = useQueryClient()
  const [creando, setCreando] = useState(false)
  const [editando, setEditando] = useState<string | null>(null)

  const equipo = useQuery({ queryKey: ['team'], queryFn: team.list })

  const invalidar = (): void => {
    void queryClient.invalidateQueries({ queryKey: ['team'] })
  }

  const dardeBaja = useMutation({
    mutationFn: (id: string) => team.deactivate(id),
    onSuccess: invalidar,
  })

  const reactivar = useMutation({
    mutationFn: (id: string) => team.update(id, { is_active: true }),
    onSuccess: invalidar,
  })

  if (equipo.isLoading) return <Spinner />

  const meta = equipo.data?.meta
  const lleno = meta?.maxUsers != null && meta.active >= meta.maxUsers

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-3">
        <h1 className="flex-1 text-xl font-bold text-[var(--text-strong)]">Equipo</h1>

        {meta != null && (
          <Badge tone={lleno ? 'warn' : 'neutral'}>
            {meta.active}
            {meta.maxUsers == null ? ' · sin tope' : ` de ${meta.maxUsers}`}
          </Badge>
        )}

        {!creando && (
          <Button onClick={() => setCreando(true)} disabled={lleno}>
            Sumar a alguien
          </Button>
        )}
      </div>

      {/* Si el plan está lleno se dice ANTES de que rellene el formulario, y se
          dice qué hacer — no sólo que no se puede. */}
      {lleno && (
        <p className="rounded-[var(--radius-md)] bg-warn-50 px-3 py-2 text-sm text-warn-700">
          Tu plan llega a {meta?.maxUsers} personas. Para sumar a alguien más, da de baja a quien
          ya no esté o sube de plan.
        </p>
      )}

      {creando && (
        <MemberForm
          roles={meta?.roles ?? []}
          onDone={() => {
            setCreando(false)
            invalidar()
          }}
          onCancel={() => setCreando(false)}
        />
      )}

      <ul className="flex flex-col gap-2">
        {equipo.data?.data.map((persona) => (
          <li key={persona.id}>
            <Card className="flex flex-col gap-3 p-4">
              <div className="flex flex-wrap items-center gap-3">
                <div className="min-w-0 flex-1">
                  <p className="font-medium text-[var(--text-strong)]">
                    {persona.name}
                    {!persona.isActive && (
                      <span className="ml-2 text-sm font-normal text-[var(--text-muted)]">
                        · de baja
                      </span>
                    )}
                  </p>
                  <p className="truncate text-sm text-[var(--text-muted)]">{persona.email}</p>
                </div>

                {persona.roleName != null && <Badge>{persona.roleName}</Badge>}

                {/* Sin PIN no entra a la caja ni a la cocina. Verlo aquí evita
                    la llamada de «a mí no me deja». */}
                {persona.hasPin ? (
                  <Badge tone="ok">Con PIN</Badge>
                ) : (
                  <Badge>Sin PIN</Badge>
                )}
              </div>

              {editando === persona.id ? (
                <MemberForm
                  member={persona}
                  roles={meta?.roles ?? []}
                  onDone={() => {
                    setEditando(null)
                    invalidar()
                  }}
                  onCancel={() => setEditando(null)}
                />
              ) : (
                <div className="flex flex-wrap gap-2">
                  <Button variant="secondary" size="sm" onClick={() => setEditando(persona.id)}>
                    Cambiar
                  </Button>

                  {persona.isActive ? (
                    <Button
                      variant="ghost"
                      size="sm"
                      aria-label={`Dar de baja a ${persona.name}`}
                      onClick={() => dardeBaja.mutate(persona.id)}
                    >
                      Dar de baja
                    </Button>
                  ) : (
                    <Button
                      variant="ghost"
                      size="sm"
                      aria-label={`Reactivar a ${persona.name}`}
                      onClick={() => reactivar.mutate(persona.id)}
                    >
                      Reactivar
                    </Button>
                  )}
                </div>
              )}
            </Card>
          </li>
        ))}
      </ul>
    </div>
  )
}

function MemberForm({
  member,
  roles,
  onDone,
  onCancel,
}: {
  member?: TeamMember
  roles: { code: string; name: string }[]
  onDone: () => void
  onCancel: () => void
}) {
  const editando = member !== undefined

  const [name, setName] = useState(member?.name ?? '')
  const [email, setEmail] = useState(member?.email ?? '')
  const [password, setPassword] = useState('')
  const [roleCode, setRoleCode] = useState(member?.roleCode ?? roles[0]?.code ?? '')
  const [pin, setPin] = useState('')
  const [error, setError] = useState<string | null>(null)

  const guardar = useMutation({
    mutationFn: () => {
      if (editando) {
        const cambios: Record<string, unknown> = { name, role_code: roleCode }

        // Sólo se manda lo que se tocó: enviar una contraseña vacía sería
        // cambiársela a alguien sin querer.
        if (password !== '') cambios['password'] = password
        if (pin !== '') cambios['pin'] = pin

        return team.update(member.id, cambios)
      }

      return team.create({
        name,
        email,
        password,
        role_code: roleCode,
        pin: pin === '' ? null : pin,
      })
    },
    onSuccess: onDone,
    onError: (failure: unknown) =>
      setError(failure instanceof ApiError ? failure.message : 'No se pudo guardar.'),
  })

  return (
    <div className="flex flex-col gap-3 rounded-[var(--radius-md)] bg-[var(--surface-sunken)] p-4">
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Nombre" required>
          {({ id }) => <Input id={id} value={name} onChange={(e) => setName(e.target.value)} />}
        </Field>

        {!editando && (
          <Field label="Correo" hint="Con este entra al panel." required>
            {({ id }) => (
              <Input
                id={id}
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
              />
            )}
          </Field>
        )}

        <Field label="Rol" required>
          {({ id }) => (
            <Select id={id} value={roleCode} onChange={(e) => setRoleCode(e.target.value)}>
              {roles.map((rol) => (
                <option key={rol.code} value={rol.code}>
                  {rol.name}
                </option>
              ))}
            </Select>
          )}
        </Field>

        <Field
          label={editando ? 'Contraseña nueva' : 'Contraseña'}
          hint={editando ? 'Déjala vacía para no cambiarla.' : 'Mínimo 8 caracteres.'}
          required={!editando}
          error={error ?? undefined}
        >
          {({ id, invalid }) => (
            <Input
              id={id}
              value={password}
              invalid={invalid}
              onChange={(e) => setPassword(e.target.value)}
            />
          )}
        </Field>

        <Field
          label="PIN"
          hint="Cuatro dígitos, para entrar a la caja y a la cocina. Opcional."
        >
          {({ id }) => (
            <Input
              id={id}
              inputMode="numeric"
              maxLength={4}
              value={pin}
              onChange={(e) => setPin(e.target.value.replace(/\D/g, ''))}
            />
          )}
        </Field>
      </div>

      <div className="flex gap-2">
        <Button variant="ghost" onClick={onCancel}>
          Mejor no
        </Button>

        <Button
          disabled={guardar.isPending || name.trim() === '' || (!editando && password.length < 8)}
          onClick={() => guardar.mutate()}
        >
          {guardar.isPending ? 'Guardando…' : 'Guardar'}
        </Button>
      </div>
    </div>
  )
}
