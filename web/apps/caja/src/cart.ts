import { useState } from 'react'
import type { Modifier, Product, SaleLine } from './api'

/**
 * Lo que el cliente lleva, mientras se arma.
 *
 * Vive sólo en memoria: si se recarga la caja a mitad de un pedido, se pierde.
 * Es lo correcto —un pedido a medias en una caja compartida, recuperado tres
 * clientes después, cobra lo que no era— y por eso no se guarda.
 *
 * **Los importes de aquí son para MIRAR.** El total que se cobra es el que
 * calcula el servidor con los precios del catálogo.
 */

export interface CartLine {
  /** Identifica la línea, no el producto: el mismo producto con distintos
   *  agregados son dos líneas. */
  key: string
  product: Product
  quantity: number
  modifiers: Modifier[]
}

/** Misma cosa = mismo producto y exactamente los mismos agregados. */
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
     * Añadir. Si ya está lo mismo con los mismos agregados, sube la cantidad:
     * dos arepas iguales son una línea de dos, no dos líneas de una — el papel
     * se lee mejor y la cocina también.
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

    /** Cambiar la cantidad. Al llegar a cero, la línea desaparece. */
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

    /** Lo que se le manda al servidor: qué y cuántos. Ningún precio. */
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
