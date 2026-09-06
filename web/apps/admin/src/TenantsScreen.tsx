import { ApiError, hasMore } from '@kombo/api-client'
import {
  Badge,
  Button,
  Card,
  CardGrid,
  EmptyState,
  Field,
  Input,
  ListFooter,
  plural,
  Select,
  Spinner,
  formatUsd,
} from '@kombo/ui'
import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { PAYMENT_METHODS, platform, type TenantRow } from './api'

/**
 * The tenants, and the only thing that matters at a glance: how many days they
 * have left.
 *
 * Negative means days overdue. It is the figure that answers "who do I have to
 * call today?" without opening anything.
 */
export function TenantsScreen({ onOpen }: { onOpen: (id: string) => void }) {
  const [search, setSearch] = useState('')
  const [status, setEstado] = useState('')
  const [creating, setCreating] = useState(false)

  // Paginated: the list had no cap at all and downloaded whole.
  const tenants = useInfiniteQuery({
    queryKey: ['tenants', search, status],
    queryFn: ({ pageParam }) => platform.tenants({ search, status, page: pageParam }),
    initialPageParam: 1,
    getNextPageParam: (last) => (hasMore(last.meta) ? last.meta.page + 1 : undefined),
  })

  const visible = tenants.data?.pages.flatMap((p) => p.data) ?? []
  const total = tenants.data?.pages[0]?.meta.total ?? 0

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-end gap-3">
        <h1 className="text-xl font-bold text-[var(--text-strong)]">Negocios</h1>
        {total > 0 && <Badge>{plural(total, 'negocio', 'negocios')}</Badge>}

        <div className="flex-1" />

        <Button onClick={() => setCreating(true)}>Dar de alta</Button>
      </div>

      <div className="flex flex-wrap gap-3">
        <div className="min-w-48 flex-1">
          <Field label="Buscar">
            {({ id }) => (
              <Input
                id={id}
                type="search"
                value={search}
                placeholder="Nombre o dirección"
                onChange={(e) => setSearch(e.target.value)}
              />
            )}
          </Field>
        </div>

        <div className="w-48">
          <Field label="Estado">
            {({ id }) => (
              <Select id={id} value={status} onChange={(e) => setEstado(e.target.value)}>
                <option value="">Todos</option>
                <option value="trial">En prueba</option>
                <option value="active">Al día</option>
                <option value="past_due">Vencidos</option>
                <option value="suspended">Suspendidos</option>
              </Select>
            )}
          </Field>
        </div>
      </div>

      {creating && <NewTenantForm onDone={() => setCreating(false)} />}

      {tenants.isLoading && <Spinner />}

      {visible.length === 0 && !tenants.isLoading && (
        <EmptyState title="No hay negocios con ese filtro" description="Prueba con otro estado." />
      )}

      <CardGrid columns={2}>
        {visible.map((tenant) => (
          <li key={tenant.id}>
            <Card className="flex flex-wrap items-center gap-3 p-4">
              <button
                type="button"
                onClick={() => onOpen(tenant.id)}
                className="min-w-0 flex-1 text-left"
              >
                <p className="font-medium text-[var(--text-strong)]">{tenant.name}</p>
                <p className="text-sm text-[var(--text-muted)]">
                  {tenant.slug} · {tenant.planName}
                </p>
              </button>

              <Expiry tenant={tenant} />

              <StatusBadge status={tenant.status} label={tenant.statusLabel} />
            </Card>
          </li>
        ))}
      </CardGrid>

      <ListFooter
        shown={visible.length}
        total={total}
        noun="negocios"
        loading={tenants.isFetchingNextPage}
        onMore={() => void tenants.fetchNextPage()}
      />
    </div>
  )
}

/**
 * How many days are left, said the way it is said.
 *
 * "Expires in 3 days" and "overdue by 12" are not the same information with a
 * different sign: the first is a reminder, the second a pending call.
 */
function Expiry({ tenant }: { tenant: TenantRow }) {
  if (tenant.daysLeft === null) return null

  const days = tenant.daysLeft

  return (
    <span
      className={`tabular text-sm ${
        days < 0 ? 'font-medium text-bad-500' : days <= 7 ? 'text-warn-700' : 'text-[var(--text-muted)]'
      }`}
    >
      {/* "días", not "d". This screen has no space problem, and the
          abbreviation makes you translate it mentally every time. */}
      {days < 0
        ? `vencido hace ${plural(Math.abs(days), 'día', 'días')}`
        : `vence en ${plural(days, 'día', 'días')}`}
    </span>
  )
}

export function StatusBadge({ status, label }: { status: string; label: string }) {
  const tone = status === 'suspended' || status === 'closed' ? 'bad' : status === 'past_due' ? 'warn' : 'ok'

  return <Badge tone={tone}>{label}</Badge>
}

function NewTenantForm({ onDone }: { onDone: () => void }) {
  const queryClient = useQueryClient()

  const [name, setName] = useState('')
  const [slug, setSlug] = useState('')
  const [planCode, setPlanCode] = useState('business')
  const [ownerName, setOwnerName] = useState('')
  const [ownerEmail, setOwnerEmail] = useState('')
  const [ownerPassword, setOwnerPassword] = useState('')
  const [error, setError] = useState<string | null>(null)

  const plans = useQuery({ queryKey: ['plans'], queryFn: () => platform.plans() })

  const create = useMutation({
    mutationFn: () =>
      platform.createTenant({
        name,
        slug,
        plan_code: planCode,
        owner_name: ownerName,
        owner_email: ownerEmail,
        owner_password: ownerPassword,
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['tenants'] })
      onDone()
    },
    onError: (failure: unknown) =>
      setError(failure instanceof ApiError ? failure.message : 'No se pudo dar de alta.'),
  })

  return (
    <Card className="flex flex-col gap-3 p-4">
      <h2 className="font-semibold text-[var(--text-strong)]">Un negocio nuevo</h2>

      <p className="text-sm text-[var(--text-muted)]">
        Queda listo para trabajar: su dueño, sus roles, los módulos del plan y un horario de
        partida. Todo o nada — un negocio a medio crear no se puede arreglar desde dentro.
      </p>

      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Nombre" required>
          {({ id }) => (
            <Input
              id={id}
              value={name}
              onChange={(e) => {
                setName(e.target.value)
                // The address is proposed from the name: typing it twice is a chance to
                // type it wrong.
                if (slug === '') return
              }}
            />
          )}
        </Field>

        <Field label="Dirección" hint="Será {dirección}.kombo.app" required>
          {({ id }) => (
            <Input
              id={id}
              value={slug}
              placeholder="elsazon"
              onChange={(e) => setSlug(e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''))}
            />
          )}
        </Field>

        <Field label="Plan" required>
          {({ id }) => (
            <Select id={id} value={planCode} onChange={(e) => setPlanCode(e.target.value)}>
              {plans.data?.data.map((plan) => (
                <option key={plan.code} value={plan.code}>
                  {plan.name} · {formatUsd(plan.priceCents)}
                </option>
              ))}
            </Select>
          )}
        </Field>

        <Field label="Nombre del dueño" required>
          {({ id }) => (
            <Input id={id} value={ownerName} onChange={(e) => setOwnerName(e.target.value)} />
          )}
        </Field>

        <Field label="Correo del dueño" required>
          {({ id }) => (
            <Input
              id={id}
              type="email"
              value={ownerEmail}
              onChange={(e) => setOwnerEmail(e.target.value)}
            />
          )}
        </Field>

        <Field label="Contraseña" hint="Mínimo 8 caracteres." required error={error ?? undefined}>
          {({ id, invalid }) => (
            <Input
              id={id}
              value={ownerPassword}
              invalid={invalid}
              onChange={(e) => setOwnerPassword(e.target.value)}
            />
          )}
        </Field>
      </div>

      <div className="flex gap-2">
        <Button variant="ghost" onClick={onDone}>
          Mejor no
        </Button>

        <Button disabled={create.isPending} onClick={() => create.mutate()}>
          {create.isPending ? 'Dando de alta…' : 'Dar de alta'}
        </Button>
      </div>
    </Card>
  )
}

/** Recording a payment: what extends the period. */
export function PaymentForm({ tenantId, onDone }: { tenantId: string; onDone: () => void }) {
  const queryClient = useQueryClient()

  const [amount, setAmount] = useState('')
  const [method, setMethod] = useState<string>('pago_movil')
  const [months, setMonths] = useState('1')
  const [reference, setReference] = useState('')

  const logIt = useMutation({
    mutationFn: () =>
      platform.registerPayment(tenantId, {
        amount_cents: Math.round(Number(amount.replace(',', '.')) * 100),
        method,
        months: Number(months),
        reference: reference.trim() || null,
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['tenant', tenantId] })
      void queryClient.invalidateQueries({ queryKey: ['tenants'] })
      onDone()
    },
  })

  return (
    <div className="flex flex-col gap-3 rounded-[var(--radius-md)] bg-[var(--surface-sunken)] p-4">
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Cuánto entró" required>
          {({ id }) => (
            <Input
              id={id}
              inputMode="decimal"
              value={amount}
              placeholder="25,00"
              onChange={(e) => setAmount(e.target.value)}
            />
          )}
        </Field>

        <Field label="Cómo" required>
          {({ id }) => (
            <Select id={id} value={method} onChange={(e) => setMethod(e.target.value)}>
              {PAYMENT_METHODS.map((m) => (
                <option key={m.value} value={m.value}>
                  {m.label}
                </option>
              ))}
            </Select>
          )}
        </Field>

        <Field label="Meses" hint="Cuánto período cubre.">
          {({ id }) => (
            <Input
              id={id}
              inputMode="numeric"
              value={months}
              onChange={(e) => setMonths(e.target.value)}
            />
          )}
        </Field>

        <Field label="Referencia">
          {({ id }) => (
            <Input id={id} value={reference} onChange={(e) => setReference(e.target.value)} />
          )}
        </Field>
      </div>

      <div className="flex gap-2">
        <Button variant="ghost" onClick={onDone}>
          Mejor no
        </Button>

        <Button disabled={logIt.isPending || amount === ''} onClick={() => logIt.mutate()}>
          {logIt.isPending ? 'Anotando…' : 'Anotar el pago'}
        </Button>
      </div>
    </div>
  )
}
