import { Button, Sheet, formatUsd } from '@kombo/ui'
import { useState } from 'react'
import type { MenuModifier, MenuModifierGroup, MenuProduct } from './api'

/**
 * Un producto con sus agregados, en una hoja que sube desde abajo.
 *
 * Abajo y no en el centro: en un teléfono, lo que hay que tocar tiene que
 * quedar donde llega el pulgar. Y con la cantidad aquí dentro, para que pedir
 * tres empanadas sea un gesto y no tres.
 */
export function ProductSheet({
  product,
  groups,
  onAdd,
  onClose,
}: {
  product: MenuProduct
  groups: MenuModifierGroup[]
  onAdd: (modifiers: MenuModifier[], quantity: number) => void
  onClose: () => void
}) {
  const [chosen, setChosen] = useState<Record<string, string[]>>({})
  const [quantity, setQuantity] = useState(1)

  const missing = groups.filter((group) => (chosen[group.id]?.length ?? 0) < group.minChoices)

  const selected: MenuModifier[] = groups.flatMap((group) =>
    group.modifiers.filter((m) => chosen[group.id]?.includes(m.id)),
  )

  const unitCents = product.priceCents + selected.reduce((sum, m) => sum + m.priceDeltaCents, 0)

  function toggle(group: MenuModifierGroup, modifier: MenuModifier): void {
    setChosen((current) => {
      const already = current[group.id] ?? []

      if (already.includes(modifier.id)) {
        return { ...current, [group.id]: already.filter((id) => id !== modifier.id) }
      }

      // Cuando sólo cabe uno, elegir otro SUSTITUYE. Nadie quiere «primero
      // quita el que pusiste».
      if (group.maxChoices <= 1) {
        return { ...current, [group.id]: [modifier.id] }
      }

      return already.length >= group.maxChoices
        ? current
        : { ...current, [group.id]: [...already, modifier.id] }
    })
  }

  return (
    <Sheet
      title={product.name}
      onClose={onClose}
      footer={
        <div className="flex items-center gap-3">
          <div className="flex items-center gap-1">
            <button
              type="button"
              aria-label="Uno menos"
              onClick={() => setQuantity((q) => Math.max(1, q - 1))}
              className="size-12 rounded-[var(--radius-md)] bg-[var(--surface-sunken)] text-xl"
            >
              −
            </button>

            <span className="tabular w-8 text-center text-lg font-semibold">{quantity}</span>

            <button
              type="button"
              aria-label="Uno más"
              onClick={() => setQuantity((q) => Math.min(99, q + 1))}
              className="size-12 rounded-[var(--radius-md)] bg-[var(--surface-sunken)] text-xl"
            >
              +
            </button>
          </div>

          <Button
            size="touch"
            className="flex-1"
            disabled={missing.length > 0}
            onClick={() => onAdd(selected, quantity)}
          >
            {missing.length > 0
              ? `Elige: ${missing[0]?.name}`
              : `Agregar · ${formatUsd(unitCents * quantity)}`}
          </Button>
        </div>
      }
    >
      <div className="flex flex-col gap-6">
        {product.photoUrl != null && (
          <img
            src={product.photoUrl}
            alt=""
            className="h-44 w-full rounded-[var(--radius-md)] object-cover"
          />
        )}

        {product.description != null && product.description !== '' && (
          <p className="text-[var(--text-default)]">{product.description}</p>
        )}

        {groups.map((group) => (
          <fieldset key={group.id}>
            <legend className="mb-2 font-medium text-[var(--text-strong)]">
              {group.name}{' '}
              <span className="font-normal text-[var(--text-muted)]">
                {group.minChoices > 0 ? '· obligatorio' : '· opcional'}
              </span>
            </legend>

            <div className="flex flex-col gap-2">
              {group.modifiers.map((modifier) => {
                const on = chosen[group.id]?.includes(modifier.id) ?? false

                return (
                  <label
                    key={modifier.id}
                    className={`flex min-h-touch cursor-pointer items-center gap-3 rounded-[var(--radius-md)] border px-4 ${
                      on ? 'border-accent-500 bg-accent-50' : 'border-[var(--surface-border)]'
                    }`}
                  >
                    <input
                      type={group.maxChoices <= 1 ? 'radio' : 'checkbox'}
                      name={group.id}
                      checked={on}
                      onChange={() => toggle(group, modifier)}
                      className="size-5 accent-[var(--accent-500)]"
                    />

                    <span className="flex-1 text-[var(--text-strong)]">{modifier.name}</span>

                    {modifier.priceDeltaCents !== 0 && (
                      <span className="tabular text-sm text-[var(--text-muted)]">
                        {modifier.priceDeltaCents > 0 ? '+' : ''}
                        {formatUsd(modifier.priceDeltaCents)}
                      </span>
                    )}
                  </label>
                )
              })}
            </div>
          </fieldset>
        ))}
      </div>
    </Sheet>
  )
}
