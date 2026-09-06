import { Badge, Button, Card, Money, plural, Spinner, formatUsd } from '@kombo/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { platform, type Usage } from './api'
import { PaymentForm, StatusBadge } from './TenantsScreen'

/**
 * A tenant's record: what they pay, until when, how much they use, and what we
 * did in their house.
 *
 * That last part is not audit decoration. It is what can be shown to the owner
 * when they ask who touched their settings — us included.
 */
export function TenantDetailScreen({ id, onBack }: { id: string; onBack: () => void }) {
  const queryClient = useQueryClient()
  const [cobrando, setCobrando] = useState(false)
  const [mirando, setMirando] = useState(false)

  const tenant = useQuery({ queryKey: ['tenant', id], queryFn: () => platform.tenant(id) })

  const setStatus = useMutation({
    mutationFn: (status: string) => platform.changeStatus(id, status),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['tenant', id] })
      void queryClient.invalidateQueries({ queryKey: ['tenants'] })
    },
  })

  if (tenant.isLoading) return <Spinner />
  if (tenant.data === undefined) return null

  const t = tenant.data

  return (
    <div className="flex flex-col gap-4">
      <button
        type="button"
        onClick={onBack}
        className="self-start text-sm text-[var(--text-muted)] underline-offset-2 hover:underline"
      >
        ‹ Todos los negocios
      </button>

      <div className="flex flex-wrap items-center gap-3">
        <h1 className="flex-1 text-xl font-bold text-[var(--text-strong)]">{t.name}</h1>
        <StatusBadge status={t.status} label={t.statusLabel} />
      </div>

      <p className="text-sm text-[var(--text-muted)]">{t.slug}</p>

      {t.subscription !== null && (
        <Card className="flex flex-col gap-3 p-4">
          <div className="flex flex-wrap items-center gap-3">
            <h2 className="flex-1 font-semibold text-[var(--text-strong)]">Suscripción</h2>
            <Badge>{t.subscription.planCode}</Badge>
          </div>

          <p className="text-sm text-[var(--text-default)]">
            {t.subscription.daysLeft >= 0 ? (
              <>
                Pagado hasta el{' '}
                <strong>{new Date(t.subscription.currentPeriodEnd).toLocaleDateString('es-VE')}</strong>{' '}
                · quedan {t.subscription.daysLeft} días.
              </>
            ) : (
              <>
                {/* And when they get cut off, which is what you have to be able to say on
                    the phone without working it out in your head. */}
                Vencido hace <strong>{Math.abs(t.subscription.daysLeft)} días</strong>. Se suspende
                el {new Date(t.subscription.suspendsAt).toLocaleDateString('es-VE')}.
              </>
            )}
          </p>

          {cobrando ? (
            <PaymentForm tenantId={id} onDone={() => setCobrando(false)} />
          ) : (
            <div className="flex flex-wrap gap-2">
              <Button onClick={() => setCobrando(true)}>Anotar un pago</Button>

              {t.status === 'suspended' ? (
                <Button variant="secondary" onClick={() => setStatus.mutate('active')}>
                  Reactivar
                </Button>
              ) : (
                <Button variant="ghost" onClick={() => setStatus.mutate('suspended')}>
                  Suspender
                </Button>
              )}
            </div>
          )}
        </Card>
      )}

      <Card className="flex flex-col gap-3 p-4">
        <h2 className="font-semibold text-[var(--text-strong)]">Cuánto usa</h2>

        <UsageBar label="Equipo" usage={t.usage.users} />
        <UsageBar label="Productos" usage={t.usage.products} />
        <UsageBar label="Pedidos este mes" usage={t.usage.ordersThisMonth} />
      </Card>

      {t.payments.length > 0 && (
        <Card className="flex flex-col gap-2 p-4">
          <h2 className="font-semibold text-[var(--text-strong)]">Pagos</h2>

          <ul className="flex flex-col gap-1">
            {t.payments.map((payment, index) => (
              <li key={index} className="flex justify-between gap-3 text-sm">
                <span className="text-[var(--text-default)]">
                  {new Date(payment.paidAt).toLocaleDateString('es-VE')} · {payment.method}
                  {payment.reference != null && ` · ${payment.reference}`}
                </span>
                <span className="tabular">{formatUsd(payment.amountCents)}</span>
              </li>
            ))}
          </ul>
        </Card>
      )}

      <Card className="flex flex-col gap-3 p-4">
        <h2 className="font-semibold text-[var(--text-strong)]">Soporte</h2>

        <p className="text-sm text-[var(--text-muted)]">
          Mirar el negocio en sólo lectura, para cuando llame diciendo que algo no le funciona.
          Queda escrito, y esa nota se le puede enseñar a él.
        </p>

        {mirando ? (
          <SupportSnapshot id={id} />
        ) : (
          <Button variant="secondary" className="self-start" onClick={() => setMirando(true)}>
            Echar un vistazo
          </Button>
        )}
      </Card>

      {t.platformLog.length > 0 && (
        <Card className="flex flex-col gap-2 p-4">
          <h2 className="font-semibold text-[var(--text-strong)]">Lo que hicimos aquí</h2>

          <ul className="flex flex-col gap-1 text-sm">
            {t.platformLog.map((entry, index) => (
              <li key={index} className="flex justify-between gap-3">
                <span className="text-[var(--text-default)]">{entry.action}</span>
                <span className="text-[var(--text-muted)]">
                  {entry.by} · {new Date(entry.at).toLocaleDateString('es-VE')}
                </span>
              </li>
            ))}
          </ul>
        </Card>
      )}
    </div>
  )
}

/**
 * Usage against the ceiling.
 *
 * `null` is UNLIMITED, and it is said in words: a bar full at 0% would be a bar
 * that means nothing.
 */
function UsageBar({ label, usage }: { label: string; usage: Usage }) {
  const unlimited = usage.max === null
  const percent = unlimited ? 0 : Math.min(100, Math.round((usage.used / (usage.max || 1)) * 100))
  const tight = !unlimited && percent >= 80

  return (
    <div>
      <p className="flex justify-between text-sm">
        <span className="text-[var(--text-default)]">{label}</span>
        <span className={`tabular ${tight ? 'font-medium text-warn-700' : 'text-[var(--text-muted)]'}`}>
          {usage.used} {unlimited ? '· sin tope' : `de ${usage.max}`}
        </span>
      </p>

      {!unlimited && (
        <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-[var(--surface-sunken)]">
          <div
            className={`h-full ${tight ? 'bg-warn-500' : 'bg-accent-500'}`}
            style={{ width: `${percent}%` }}
          />
        </div>
      )}
    </div>
  )
}

function SupportSnapshot({ id }: { id: string }) {
  const glance = useQuery({ queryKey: ['support', id], queryFn: () => platform.support(id) })

  if (glance.isLoading) return <Spinner label="Mirando…" />
  if (glance.data === undefined) return null

  return (
    <div className="flex flex-col gap-3 rounded-[var(--radius-md)] bg-[var(--surface-sunken)] p-4 text-sm">
      <p className="text-[var(--text-default)]">
        {plural(glance.data.products, 'producto', 'productos')} · módulos:{' '}
        {glance.data.modules.join(', ')}
      </p>

      <div>
        <p className="mb-1 font-medium text-[var(--text-strong)]">Equipo</p>
        <ul className="flex flex-col gap-0.5">
          {glance.data.team.map((person) => (
            <li key={person.email} className="text-[var(--text-muted)]">
              {person.name} · {person.email}
            </li>
          ))}
        </ul>
      </div>

      <div>
        <p className="mb-1 font-medium text-[var(--text-strong)]">Últimos pedidos</p>
        <ul className="flex flex-col gap-0.5">
          {glance.data.lastOrders.map((order) => (
            <li key={order.number} className="flex justify-between text-[var(--text-muted)]">
              <span>
                #{order.number} · {order.status} · {order.channel}
              </span>
              <Money cents={order.totalCents} scale="sm" />
            </li>
          ))}
        </ul>
      </div>
    </div>
  )
}
