import { Button, EmptyState, Money, Spinner, formatUsd } from '@kombo/ui'
import { useEffect, useMemo, useState } from 'react'
import {
  counter,
  type Category,
  type DeliveryNote,
  type Modifier,
  type ModifierGroup,
  type Product,
  type SalePayment,
} from './api'
import { lineTotalCents, useCart } from './cart'
import { ModifierSheet } from './ModifierSheet'
import { NoteSheet } from './NoteSheet'
import { PaymentSheet } from './PaymentSheet'

/**
 * La caja.
 *
 * **Cuadrícula de productos, no un buscador**: en comida no se escanea nada,
 * se toca la arepa. Y **una sola acción primaria** —Cobrar—, siempre en el
 * mismo sitio, alcanzable con el pulgar.
 */
export function Register({
  needsPin,
  operator,
  onLeave,
}: {
  needsPin: boolean
  /** Quien opera según `/me`. Va en la cabecera: lo que se venda lleva su nombre. */
  operator: string
  /** `null` en supervisión: no hay turno que cerrar y la salida la lleva la banda. */
  onLeave: (() => void) | null
}) {
  const [products, setProducts] = useState<Product[]>([])
  const [categories, setCategories] = useState<Category[]>([])
  const [groups, setGroups] = useState<ModifierGroup[]>([])
  const [rate, setRate] = useState<number | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const [category, setCategory] = useState<string | null>(null)
  const [choosing, setChoosing] = useState<Product | null>(null)
  const [charging, setCharging] = useState(false)
  const [note, setNote] = useState<DeliveryNote | null>(null)

  const cart = useCart()

  useEffect(() => {
    Promise.all([
      counter.products(),
      counter.categories(),
      counter.modifierGroups(),
      counter.rate(),
    ])
      .then(([productos, categorias, agregados, tasa]) => {
        setProducts(productos)
        setCategories(categorias)
        setGroups(agregados)
        setRate(tasa)
      })
      .catch(() => setError('No se pudo cargar la carta.'))
      .finally(() => setLoading(false))
  }, [])

  const visible = useMemo(
    () =>
      products
        .filter((product) => product.isActive && !product.isSoldOut)
        .filter((product) => category === null || product.categoryId === category),
    [products, category],
  )

  /** Los grupos de agregados de un producto, ya resueltos. */
  function groupsOf(product: Product): ModifierGroup[] {
    const ids = product.modifierGroupIds ?? []

    return groups.filter((group) => group.isActive && ids.includes(group.id))
  }

  function choose(product: Product): void {
    const suyos = groupsOf(product)

    // Sin agregados, un toque basta. Abrir una hoja para no preguntar nada
    // sería un toque de más en el producto que más se vende.
    if (suyos.length === 0) {
      cart.add(product)
      return
    }

    setChoosing(product)
  }

  async function charge(payments: SalePayment[]): Promise<void> {
    const sale = await counter.sell({
      items: cart.toPayload(),
      payments,
      service_type: 'takeaway',
    })

    cart.clear()
    setCharging(false)
    setNote(sale.note)
  }

  if (loading) return <Spinner label="Cargando la carta…" />

  return (
    // `min-h-0` y no `h-dvh`: la altura la manda quien nos monta, que es quien
    // sabe si además hay una banda arriba.
    <div className="flex min-h-0 flex-1 flex-col bg-[var(--surface-sunken)]">
      <header className="flex h-14 shrink-0 items-center gap-3 border-b border-[var(--surface-border)] bg-[var(--surface-raised)] px-4">
        <h1 className="font-semibold text-[var(--text-strong)]">Caja</h1>

        {/* Quién está vendiendo. No es decorativo: en una máquina que se pasan
            tres personas en un turno, es lo que hace evidente que el nombre
            que va a llevar la venta no es el que uno cree. */}
        <span className="min-w-0 truncate text-sm text-[var(--text-muted)]">· {operator}</span>

        <div className="flex-1" />

        {rate != null && (
          <span className="tabular text-sm text-[var(--text-muted)]">
            Bs {rate.toLocaleString('es-VE')} / $
          </span>
        )}

        {onLeave != null && (
          <button
            type="button"
            onClick={onLeave}
            className="min-h-11 shrink-0 text-sm text-[var(--text-muted)] underline-offset-2 hover:underline"
          >
            Salir
          </button>
        )}
      </header>

      {error != null && (
        <p role="alert" className="bg-bad-50 px-4 py-2 text-sm font-medium text-bad-700">
          {error}
        </p>
      )}

      <div className="flex flex-1 flex-col overflow-hidden md:flex-row">
        <section className="flex flex-1 flex-col overflow-hidden" aria-label="Carta">
          {categories.length > 0 && (
            <div className="flex shrink-0 gap-2 overflow-x-auto p-3">
              <CategoryTab active={category === null} onClick={() => setCategory(null)}>
                Todo
              </CategoryTab>

              {categories
                .filter((c) => c.isActive)
                .map((c) => (
                  <CategoryTab
                    key={c.id}
                    active={category === c.id}
                    onClick={() => setCategory(c.id)}
                  >
                    {c.name}
                  </CategoryTab>
                ))}
            </div>
          )}

          {/* Una columna más en pantallas muy anchas. La caja no lleva tope de
              ancho —es una herramienta a pantalla completa, no un documento—,
              así que en un monitor grande cuatro columnas dejaban tarjetas de
              cuatrocientos píxeles para un nombre y un precio. */}
          <div className="grid flex-1 auto-rows-min grid-cols-2 gap-3 overflow-y-auto px-3 pb-3 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-5">
            {visible.map((product) => (
              <button
                key={product.id}
                type="button"
                onClick={() => choose(product)}
                className="flex min-h-touch flex-col justify-between overflow-hidden rounded-[var(--radius-md)] bg-[var(--surface-raised)] p-3 text-left shadow-[var(--shadow-card)] active:bg-[var(--surface-sunken)]"
              >
                {product.photoUrl != null && (
                  <img
                    src={product.photoUrl}
                    alt=""
                    className="mb-2 h-24 w-full rounded-[var(--radius-sm)] object-cover"
                  />
                )}

                <span className="font-medium text-[var(--text-strong)]">{product.name}</span>
                <span className="tabular mt-1 text-sm text-[var(--text-muted)]">
                  {formatUsd(product.priceCents)}
                </span>
              </button>
            ))}

            {visible.length === 0 && (
              <div className="col-span-full">
                <EmptyState
                  title="Aquí no hay nada que vender"
                  description="Carga la carta desde el panel, o elige otra categoría."
                />
              </div>
            )}
          </div>
        </section>

        <TicketPanel
          cart={cart}
          rate={rate}
          onCharge={() => setCharging(true)}
        />
      </div>

      {choosing != null && (
        <ModifierSheet
          product={choosing}
          groups={groupsOf(choosing)}
          onClose={() => setChoosing(null)}
          onAdd={(modifiers: Modifier[]) => {
            cart.add(choosing, modifiers)
            setChoosing(null)
          }}
        />
      )}

      {charging && (
        <PaymentSheet
          totalCents={cart.totalCents}
          rate={rate}
          onClose={() => setCharging(false)}
          onConfirm={charge}
        />
      )}

      {note != null && (
        <NoteSheet note={note} needsPin={needsPin} onDone={() => setNote(null)} />
      )}
    </div>
  )
}

function CategoryTab({
  active,
  onClick,
  children,
}: {
  active: boolean
  onClick: () => void
  children: string
}) {
  return (
    <button
      type="button"
      aria-pressed={active}
      onClick={onClick}
      className={`min-h-11 shrink-0 rounded-full px-4 text-sm font-medium ${
        active
          ? 'bg-accent-500 text-white'
          : 'bg-[var(--surface-raised)] text-[var(--text-default)]'
      }`}
    >
      {children}
    </button>
  )
}

/**
 * Lo que lleva el cliente, y el único botón que importa.
 *
 * A la derecha en pantalla ancha y abajo en un teléfono, pero **siempre en el
 * mismo sitio dentro de su pantalla**: cobrar no puede moverse de lugar según
 * cuántas líneas haya.
 */
function TicketPanel({
  cart,
  rate,
  onCharge,
}: {
  cart: ReturnType<typeof useCart>
  rate: number | null
  onCharge: () => void
}) {
  return (
    <aside
      aria-label="La cuenta"
      className="flex shrink-0 flex-col border-t border-[var(--surface-border)] bg-[var(--surface-raised)] md:w-96 md:border-l md:border-t-0"
    >
      {/* Con la cuenta vacía esto era una columna en blanco. Quien se sienta
          por primera vez en la caja necesita que la pantalla le diga qué hacer,
          y «toca un producto» son cuatro palabras que se leen una vez y ya. */}
      {cart.lines.length === 0 && (
        <p className="flex-1 px-4 py-8 text-center text-sm text-[var(--text-muted)]">
          Toca un producto para empezar la cuenta.
        </p>
      )}

      <ul className="max-h-48 flex-1 overflow-y-auto md:max-h-none">
        {cart.lines.map((line) => (
          <li
            key={line.key}
            className="flex items-center gap-3 border-b border-[var(--surface-border)] px-4 py-3"
          >
            <div className="flex-1">
              <p className="font-medium text-[var(--text-strong)]">{line.product.name}</p>

              {line.modifiers.length > 0 && (
                <p className="text-xs text-[var(--text-muted)]">
                  {line.modifiers.map((m) => m.name).join(' · ')}
                </p>
              )}
            </div>

            <div className="flex items-center gap-1">
              <button
                type="button"
                aria-label={`Quitar uno de ${line.product.name}`}
                onClick={() => cart.setQuantity(line.key, line.quantity - 1)}
                className="size-11 rounded-[var(--radius-md)] bg-[var(--surface-sunken)] text-lg"
              >
                −
              </button>

              <span className="tabular w-8 text-center font-medium">{line.quantity}</span>

              <button
                type="button"
                aria-label={`Agregar uno de ${line.product.name}`}
                onClick={() => cart.setQuantity(line.key, line.quantity + 1)}
                className="size-11 rounded-[var(--radius-md)] bg-[var(--surface-sunken)] text-lg"
              >
                +
              </button>
            </div>

            <span className="tabular w-16 text-right text-sm">
              {formatUsd(lineTotalCents(line))}
            </span>
          </li>
        ))}
      </ul>

      <div className="shrink-0 border-t border-[var(--surface-border)] p-4">
        <div className="mb-3 flex items-end justify-between">
          <span className="text-[var(--text-muted)]">Total</span>
          <Money cents={cart.totalCents} rate={rate} scale="xl" />
        </div>

        <Button size="touch" block disabled={cart.lines.length === 0} onClick={onCharge}>
          Cobrar
        </Button>
      </div>
    </aside>
  )
}
