import { ApiError } from '@kombo/api-client'
import { Badge, Button, Card, Field, Input, Select, Spinner, Page} from '@kombo/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { team, type TeamMember } from '../api/team'

/**
 * Who works here.
 *
 * What it takes for a shop to grow from one person to five without anyone
 * touching the database: add somebody, give them a role, set a PIN, and
 * deactivate them the day they leave.
 *
 * The PIN shows as yes/no, never as a number. It authorises voiding a sale:
 * showing it on a screen somebody may be reading over your shoulder would be
 * giving it away.
 */
export function TeamScreen() {
  const queryClient = useQueryClient()
  const [creating, setCreating] = useState(false)
  const [editing, setEditing] = useState<string | null>(null)

  const members = useQuery({ queryKey: ['team'], queryFn: team.list })

  const invalidate = (): void => {
    void queryClient.invalidateQueries({ queryKey: ['team'] })
  }

  const deactivate = useMutation({
    mutationFn: (id: string) => team.deactivate(id),
    onSuccess: invalidate,
  })

  const reactivate = useMutation({
    mutationFn: (id: string) => team.update(id, { is_active: true }),
    onSuccess: invalidate,
  })

  if (members.isLoading) return <Spinner />

  const meta = members.data?.meta
  const full = meta?.maxUsers != null && meta.active >= meta.maxUsers

  return (
    <Page width="board" className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-3">
        <h1 className="flex-1 text-xl font-bold text-[var(--text-strong)]">Equipo</h1>

        {meta != null && (
          <Badge tone={full ? 'warn' : 'neutral'}>
            {meta.active}
            {meta.maxUsers == null ? ' · sin tope' : ` de ${meta.maxUsers}`}
          </Badge>
        )}

        {!creating && (
          <Button onClick={() => setCreating(true)} disabled={full}>
            Sumar a alguien
          </Button>
        )}
      </div>

      {/* If the plan is full it is said BEFORE the form is filled in, and it
          says what to do — not just that it cannot be done. */}
      {full && (
        <p className="rounded-[var(--radius-md)] bg-warn-50 px-3 py-2 text-sm text-warn-700">
          Tu plan llega a {meta?.maxUsers} personas. Para sumar a alguien más, da de baja a quien
          ya no esté o sube de plan.
        </p>
      )}

      {creating && (
        <MemberForm
          roles={meta?.roles ?? []}
          onDone={() => {
            setCreating(false)
            invalidate()
          }}
          onCancel={() => setCreating(false)}
        />
      )}

      <ul className="flex flex-col gap-2">
        {members.data?.data.map((person) => (
          <li key={person.id}>
            <Card className="flex flex-col gap-3 p-4">
              <div className="flex flex-wrap items-center gap-3">
                <div className="min-w-0 flex-1">
                  <p className="font-medium text-[var(--text-strong)]">
                    {person.name}
                    {!person.isActive && (
                      <span className="ml-2 text-sm font-normal text-[var(--text-muted)]">
                        · de baja
                      </span>
                    )}
                  </p>
                  <p className="truncate text-sm text-[var(--text-muted)]">{person.email}</p>
                </div>

                {person.roleName != null && <Badge>{person.roleName}</Badge>}

                {/* Without a PIN they reach neither the till nor the kitchen. Seeing
                    it here heads off the "it won't let me in" call. */}
                {person.hasPin ? (
                  <Badge tone="ok">Con PIN</Badge>
                ) : (
                  <Badge>Sin PIN</Badge>
                )}
              </div>

              {editing === person.id ? (
                <MemberForm
                  member={person}
                  roles={meta?.roles ?? []}
                  onDone={() => {
                    setEditing(null)
                    invalidate()
                  }}
                  onCancel={() => setEditing(null)}
                />
              ) : (
                <div className="flex flex-wrap gap-2">
                  <Button variant="secondary" size="sm" onClick={() => setEditing(person.id)}>
                    Cambiar
                  </Button>

                  {person.isActive ? (
                    <Button
                      variant="ghost"
                      size="sm"
                      aria-label={`Dar de baja a ${person.name}`}
                      onClick={() => deactivate.mutate(person.id)}
                    >
                      Dar de baja
                    </Button>
                  ) : (
                    <Button
                      variant="ghost"
                      size="sm"
                      aria-label={`Reactivar a ${person.name}`}
                      onClick={() => reactivate.mutate(person.id)}
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
    </Page>
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
  const editing = member !== undefined

  const [name, setName] = useState(member?.name ?? '')
  const [email, setEmail] = useState(member?.email ?? '')
  const [password, setPassword] = useState('')
  const [roleCode, setRoleCode] = useState(member?.roleCode ?? roles[0]?.code ?? '')
  const [pin, setPin] = useState('')
  const [error, setError] = useState<string | null>(null)

  const save = useMutation({
    mutationFn: () => {
      if (editing) {
        const changes: Record<string, unknown> = { name, role_code: roleCode }

        // Only what was touched is sent: submitting an empty password would change
        // somebody's by accident.
        if (password !== '') changes['password'] = password
        if (pin !== '') changes['pin'] = pin

        return team.update(member.id, changes)
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

        {!editing && (
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
              {roles.map((role) => (
                <option key={role.code} value={role.code}>
                  {role.name}
                </option>
              ))}
            </Select>
          )}
        </Field>

        <Field
          label={editing ? 'Contraseña nueva' : 'Contraseña'}
          hint={editing ? 'Déjala vacía para no cambiarla.' : 'Mínimo 8 caracteres.'}
          required={!editing}
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
          disabled={save.isPending || name.trim() === '' || (!editing && password.length < 8)}
          onClick={() => save.mutate()}
        >
          {save.isPending ? 'Guardando…' : 'Guardar'}
        </Button>
      </div>
    </div>
  )
}
