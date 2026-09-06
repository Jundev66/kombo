import { useCallback, useEffect, useState } from 'react'
import type { MenuModifier, MenuProduct } from './api'

/**
 * The customer's basket.
 *
 * Kept in `localStorage`, unlike the till's — and the difference is not a whim:
 * the till is a shared machine where a half-built order recovered three
 * customers later charges the wrong thing. This is one person's phone: they
 * browse the menu, take a call, and come back ten minutes later expecting to
 * find their order.
 *
 * The tenant slug goes in the key just in case, even though the browser already
 * isolates `localStorage` per origin and each tenant is its own origin.
 */

export interface CartLine {
  key: string
  productId: string
  name: string
  unitPriceCents: number
  quantity: number
  modifiers: { id: string; name: string; priceDeltaCents: number }[]
}

function storageKey(slug: string): string {
  return `kombo.cart.${slug}`
}

function lineKey(productId: string, modifiers: { id: string }[]): string {
  return [productId, ...modifiers.map((m) => m.id).sort()].join('|')
}

export function lineTotalCents(line: CartLine): number {
  const unit = line.unitPriceCents + line.modifiers.reduce((sum, m) => sum + m.priceDeltaCents, 0)

  return unit * line.quantity
}

function read(slug: string): CartLine[] {
  try {
    const raw = localStorage.getItem(storageKey(slug))

    return raw === null ? [] : (JSON.parse(raw) as CartLine[])
  } catch {
    // An unreadable basket — another format version, somebody in the console —
    // is discarded silently. Starting empty is annoying; starting broken leaves
    // the customer unable to order.
    return []
  }
}

export function useCart(slug: string) {
  const [lines, setLines] = useState<CartLine[]>(() => read(slug))

  useEffect(() => {
    try {
      localStorage.setItem(storageKey(slug), JSON.stringify(lines))
    } catch {
      // Incognito mode with storage full. Not being able to save cannot stop them
      // ordering: the basket stays alive in memory.
    }
  }, [slug, lines])

  const add = useCallback((product: MenuProduct, modifiers: MenuModifier[], quantity = 1): void => {
    const key = lineKey(product.id, modifiers)

    setLines((current) => {
      const existing = current.find((line) => line.key === key)

      if (existing) {
        return current.map((line) =>
          line.key === key ? { ...line, quantity: line.quantity + quantity } : line,
        )
      }

      return [
        ...current,
        {
          key,
          productId: product.id,
          name: product.name,
          unitPriceCents: product.priceCents,
          quantity,
          modifiers: modifiers.map((m) => ({
            id: m.id,
            name: m.name,
            priceDeltaCents: m.priceDeltaCents,
          })),
        },
      ]
    })
  }, [])

  const setQuantity = useCallback((key: string, quantity: number): void => {
    setLines((current) =>
      quantity <= 0
        ? current.filter((line) => line.key !== key)
        : current.map((line) => (line.key === key ? { ...line, quantity } : line)),
    )
  }, [])

  const clear = useCallback((): void => setLines([]), [])

  return {
    lines,
    count: lines.reduce((sum, line) => sum + line.quantity, 0),
    subtotalCents: lines.reduce((sum, line) => sum + lineTotalCents(line), 0),
    add,
    setQuantity,
    clear,

    /** What is sent to the server: what and how many. No prices. */
    toPayload: () =>
      lines.map((line) => ({
        product_id: line.productId,
        quantity: line.quantity,
        modifier_ids: line.modifiers.map((m) => m.id),
      })),
  }
}

export type Cart = ReturnType<typeof useCart>
