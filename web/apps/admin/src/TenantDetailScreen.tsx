import { Badge, Button, Card, Money, plural, Spinner, formatUsd } from '@kombo/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { platform, type Usage } from './api'
import { PaymentForm, StatusBadge } from './TenantsScreen'

/**
 * La ficha de un negocio: qué paga, hasta cuándo, cuánto usa y qué hicimos
 * nosotros en su casa.
 *
 * Esa última parte no es un adorno de auditoría. Es lo que se le puede enseñar
 * al dueño cuando pregunte quién tocó su configuración — incluidos nosotros.
 */
export function TenantDetailScreen({ id, onBack }: { id: string; onBack: () => void }) {
  const queryClient = useQueryClient()
  const [cobrando, setCobrando] = useState(false)
  const [mirando, setMirando] = useState(false)

  const tenant = useQuery({ queryKey: ['tenant', id], queryFn: () => platform.tenant(id) })

  const cambiarEstado = useMutation({
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
                {/* Y cuándo se le corta, que es lo que hay que poder decirle
                    por teléfono sin calcularlo de cabeza. */}
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
                <Button variant="secondary" onClick={() => cambiarEstado.mutate('active')}>
                  Reactivar
                </Button>
              ) : (
                <Button variant="ghost" onClick={() => cambiarEstado.mutate('suspended')}>
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
            {t.payments.map((pago, index) => (
              <li key={index} className="flex justify-between gap-3 text-sm">
                <span className="text-[var(--text-default)]">
                  {new Date(pago.paidAt).toLocaleDateString('es-VE')} · {pago.method}
                  {pago.reference != null && ` · ${pago.reference}`}
                </span>
                <span className="tabular">{formatUsd(pago.amountCents)}</span>
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
            {t.platformLog.map((entrada, index) => (
              <li key={index} className="flex justify-between gap-3">
                <span className="text-[var(--text-default)]">{entrada.action}</span>
                <span className="text-[var(--text-muted)]">
                  {entrada.by} · {new Date(entrada.at).toLocaleDateString('es-VE')}
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
 * El uso contra el techo.
 *
 * `null` es ILIMITADO, y se dice con la palabra: una barra llena al 0 % sería
 * una barra que no significa nada.
 */
function UsageBar({ label, usage }: { label: string; usage: Usage }) {
  const ilimitado = usage.max === null
  const porcentaje = ilimitado ? 0 : Math.min(100, Math.round((usage.used / (usage.max || 1)) * 100))
  const apretado = !ilimitado && porcentaje >= 80

  return (
    <div>
      <p className="flex justify-between text-sm">
        <span className="text-[var(--text-default)]">{label}</span>
        <span className={`tabular ${apretado ? 'font-medium text-warn-700' : 'text-[var(--text-muted)]'}`}>
          {usage.used} {ilimitado ? '· sin tope' : `de ${usage.max}`}
        </span>
      </p>

      {!ilimitado && (
        <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-[var(--surface-sunken)]">
          <div
            className={`h-full ${apretado ? 'bg-warn-500' : 'bg-accent-500'}`}
            style={{ width: `${porcentaje}%` }}
          />
        </div>
      )}
    </div>
  )
}

function SupportSnapshot({ id }: { id: string }) {
  const vistazo = useQuery({ queryKey: ['support', id], queryFn: () => platform.support(id) })

  if (vistazo.isLoading) return <Spinner label="Mirando…" />
  if (vistazo.data === undefined) return null

  return (
    <div className="flex flex-col gap-3 rounded-[var(--radius-md)] bg-[var(--surface-sunken)] p-4 text-sm">
      <p className="text-[var(--text-default)]">
        {plural(vistazo.data.products, 'producto', 'productos')} · módulos:{' '}
        {vistazo.data.modules.join(', ')}
      </p>

      <div>
        <p className="mb-1 font-medium text-[var(--text-strong)]">Equipo</p>
        <ul className="flex flex-col gap-0.5">
          {vistazo.data.team.map((persona) => (
            <li key={persona.email} className="text-[var(--text-muted)]">
              {persona.name} · {persona.email}
            </li>
          ))}
        </ul>
      </div>

      <div>
        <p className="mb-1 font-medium text-[var(--text-strong)]">Últimos pedidos</p>
        <ul className="flex flex-col gap-0.5">
          {vistazo.data.lastOrders.map((pedido) => (
            <li key={pedido.number} className="flex justify-between text-[var(--text-muted)]">
              <span>
                #{pedido.number} · {pedido.status} · {pedido.channel}
              </span>
              <Money cents={pedido.totalCents} scale="sm" />
            </li>
          ))}
        </ul>
      </div>
    </div>
  )
}
