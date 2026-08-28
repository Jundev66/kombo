import { expect, type Page } from '@playwright/test'
import { addressOf, portalOf } from './addresses'

/**
 * El portal, como lo usa alguien de la calle.
 *
 * Sin sesión, sin cuenta y sin nada guardado: cada prueba arranca con un
 * navegador limpio, que es exactamente la situación de un cliente que abre el
 * enlace por primera vez.
 */

/** Abre la carta y espera a que cargue de verdad. */
export async function openMenu(page: Page, tenant: string): Promise<void> {
  await page.goto(portalOf(tenant))

  // Se espera a algo del NEGOCIO, no a un texto de la plataforma: hasta que no
  // llega `/portal/shop` no hay carta que tocar.
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
}

/** El seguimiento de un pedido, por su enlace. */
export function trackAddress(tenant: string, token: string): string {
  return addressOf(tenant, `/p/${token}`)
}

/**
 * Añade un producto al pedido, pasando por su hoja.
 *
 * Todos los productos abren la hoja —también los que no tienen agregados—
 * porque ahí es donde está la cantidad.
 */
export async function addToCart(page: Page, product: string, quantity = 1): Promise<void> {
  await page.getByRole('button', { name: product }).click()

  const hoja = page.getByRole('dialog', { name: product })
  await expect(hoja).toBeVisible()

  for (let i = 1; i < quantity; i++) {
    await hoja.getByRole('button', { name: 'Uno más' }).click()
  }

  await hoja.getByRole('button', { name: /Agregar/ }).click()
  await expect(hoja).toBeHidden()
}

/** La barra de abajo, que dice cuánto llevas. */
export function cartBar(page: Page) {
  return page.getByRole('link', { name: /Ver mi pedido/ })
}
