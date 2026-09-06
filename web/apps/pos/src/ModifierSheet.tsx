import { Button, Sheet, formatUsd } from '@kombo/ui'
import { useState } from 'react'
import type { Modifier, ModifierGroup, Product } from './api'

/**
 * A product's add-ons.
 *
 * It opens on tapping something that has them, and does not let you past
 * without choosing the required ones: "how would you like the meat?" is not a
 * question you can skip and settle later at the griddle.
 */
export function ModifierSheet({
  product,
  groups,
  onAdd,
  onClose,
}: {
  product: Product
  groups: ModifierGroup[]
  onAdd: (modifiers: Modifier[]) => void
  onClose: () => void
}) {
  const [chosen, setChosen] = useState<Record<string, string[]>>({})

  const missing = groups.filter(
    (group) => (chosen[group.id]?.length ?? 0) < group.minChoices,
  )

  const selected: Modifier[] = groups.flatMap((group) =>
    group.modifiers.filter((m) => chosen[group.id]?.includes(m.id)),
  )

  const totalCents =
    product.priceCents + selected.reduce((sum, m) => sum + m.priceDeltaCents, 0)

  function toggle(group: ModifierGroup, modifier: Modifier): void {
    setChosen((current) => {
      const already = current[group.id] ?? []

      if (already.includes(modifier.id)) {
        return { ...current, [group.id]: already.filter((id) => id !== modifier.id) }
      }

      // When only one fits, choosing another REPLACES it rather than bouncing:
      // nobody wants "first remove the one you picked" with a customer waiting.
      if (group.maxChoices <= 1) {
        return { ...current, [group.id]: [modifier.id] }
      }

      if (already.length >= group.maxChoices) {
        return current
      }

      return { ...current, [group.id]: [...already, modifier.id] }
    })
  }

  return (
    <Sheet
      title={product.name}
      onClose={onClose}
      footer={
        <Button
          size="touch"
          block
          disabled={missing.length > 0}
          onClick={() => onAdd(selected)}
        >
          {missing.length > 0
            ? `Falta elegir: ${missing[0]?.name}`
            : `Agregar · ${formatUsd(totalCents)}`}
        </Button>
      }
    >
      <div className="flex flex-col gap-6">
        {groups.map((group) => (
          <fieldset key={group.id}>
            <legend className="mb-2 font-medium text-[var(--text-strong)]">
              {group.name}{' '}
              {/* The SERVER explains the rule: "Pick one option." It is not
                  reworded here, so the two cannot disagree. */}
              <span className="font-normal text-[var(--text-muted)]">{group.rule}</span>
            </legend>

            <div className="flex flex-col gap-2">
              {group.modifiers
                .filter((modifier) => modifier.isActive)
                .map((modifier) => {
                  const on = chosen[group.id]?.includes(modifier.id) ?? false

                  return (
                    <label
                      key={modifier.id}
                      className={`flex min-h-touch cursor-pointer items-center gap-3 rounded-[var(--radius-md)] border px-4 ${
                        on
                          ? 'border-accent-500 bg-accent-50'
                          : 'border-[var(--surface-border)]'
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
