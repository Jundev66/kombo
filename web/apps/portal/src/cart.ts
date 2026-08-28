import { useCallback, useEffect, useState } from 'react'
import type { MenuModifier, MenuProduct } from './api'

/**
 * El carrito del cliente.
 *
 * **Se guarda en `localStorage`, al revés que el de la caja.** Y la diferencia
 * no es capricho: la caja es una máquina compartida donde un pedido a medias
 * recuperado tres clientes después cobra lo que no es. Aquí es el teléfono de
 * una persona, que va a mirar el menú, le entra una llamada, y vuelve diez
 * minutos después esperando encontrar lo suyo.
 *
 * Va con el **slug del negocio en la clave** por si acaso, aunque el navegador
 * ya aísla `localStorage` por origen y cada negocio es su propio origen. Es un
 * cinturón sobre los tirantes, y cuesta una línea.
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
    // Un carrito ilegible —otra versión del formato, alguien tocando la
    // consola— se descarta en silencio. Arrancar vacío es molesto; arrancar
    // roto deja al cliente sin poder pedir.
    return []
  }
}

export function useCart(slug: string) {
  const [lines, setLines] = useState<CartLine[]>(() => read(slug))

  useEffect(() => {
    try {
      localStorage.setItem(storageKey(slug), JSON.stringify(lines))
    } catch {
      // Modo incógnito con el almacenamiento lleno. Que no se pueda guardar no
      // puede impedir pedir: el carrito sigue vivo en memoria.
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

    /** Lo que se le manda al servidor: qué y cuántos. Ningún precio. */
    toPayload: () =>
      lines.map((line) => ({
        product_id: line.productId,
        quantity: line.quantity,
        modifier_ids: line.modifiers.map((m) => m.id),
      })),
  }
}

export type Cart = ReturnType<typeof useCart>
