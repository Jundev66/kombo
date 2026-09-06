import { useState } from 'react'
import type { Modifier, Product, SaleLine } from './api'

/**
 * What the customer is taking, while it is being built.
 *
 * In memory only: reloading the till mid-order loses it. That is right — a
 * half-built order on a shared till, recovered three customers later, charges
 * the wrong thing.
 *
 * The amounts here are for LOOKING AT. The total charged is the one the server
 * computes from catalog prices.
 */

export interface CartLine {
  /** Identifies the line, not the product: the same product with different
   *  add-ons is two lines. */
  key: string
  product: Product
  quantity: number
  modifiers: Modifier[]
}

/** Same thing = same product and exactly the same add-ons. */
function lineKey(product: Product, modifiers: Modifier[]): string {
  return [product.id, ...modifiers.map((m) => m.id).sort()].join('|')
}

export function lineTotalCents(line: CartLine): number {
  const unit = line.product.priceCents + line.modifiers.reduce((sum, m) => sum + m.priceDeltaCents, 0)

  return unit * line.quantity
}

export function useCart() {
  const [lines, setLines] = useState<CartLine[]>([])

  return {
    lines,

    totalCents: lines.reduce((sum, line) => sum + lineTotalCents(line), 0),

    /**
     * Adding. If the same thing with the same add-ons is already there, the
     * quantity goes up: two identical arepas are one line of two, not two lines
     * of one — the paper reads better and so does the kitchen.
     */
    add(product: Product, modifiers: Modifier[] = []): void {
      const key = lineKey(product, modifiers)

      setLines((current) => {
        const existing = current.find((line) => line.key === key)

        if (existing) {
          return current.map((line) =>
            line.key === key ? { ...line, quantity: line.quantity + 1 } : line,
          )
        }

        return [...current, { key, product, quantity: 1, modifiers }]
      })
    },

    /** Changing the quantity. At zero, the line disappears. */
    setQuantity(key: string, quantity: number): void {
      setLines((current) =>
        quantity <= 0
          ? current.filter((line) => line.key !== key)
          : current.map((line) => (line.key === key ? { ...line, quantity } : line)),
      )
    },

    clear(): void {
      setLines([])
    },

    /** What is sent to the server: what and how many. No prices. */
    toPayload(): SaleLine[] {
      return lines.map((line) => ({
        product_id: line.product.id,
        quantity: line.quantity,
        modifier_ids: line.modifiers.map((m) => m.id),
      }))
    },
  }
}

export type Cart = ReturnType<typeof useCart>
