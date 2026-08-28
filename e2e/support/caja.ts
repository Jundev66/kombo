import { expect, type Page } from '@playwright/test'
import { cajaOf, PASSWORD } from './addresses'
import { apiPost } from './api'

/**
 * Entrar a la caja, por sus dos puertas.
 *
 * Las mismas que la cocina y por la misma razón: es una máquina compartida del
 * local. El alta se hace una vez en la vida del aparato con correo y
 * contraseña; después se entra con un PIN de cuatro dígitos.
 */
export async function enterRegister(
  page: Page,
  tenant: string,
  cajero: string,
  pin: string,
  altaCon = 'maria@elsazon.test',
): Promise<void> {
  await page.goto(cajaOf(tenant))

  if (await page.getByRole('button', { name: 'Dar de alta' }).isVisible()) {
    await page.getByLabel('Correo').fill(altaCon)
    await page.getByLabel('Contraseña').fill(PASSWORD)
    await page.getByRole('button', { name: 'Dar de alta' }).click()
  }

  await expect(page.getByRole('heading', { name: '¿Quién está en la caja?' })).toBeVisible()
  await page.getByRole('button', { name: cajero }).click()

  // Se envía solo al cuarto dígito. Un botón de confirmar de más es un toque
  // de más con un cliente esperando.
  for (const digit of pin) {
    await page.getByRole('button', { name: digit, exact: true }).click()
  }
}

/**
 * Un producto en la carta, sembrado por la API con la sesión del panel.
 *
 * Se siembra por API y no por la pantalla del catálogo a propósito: lo que
 * esta prueba viene a comprobar es la caja, y recorrer el formulario de
 * productos aquí haría que un fallo en el catálogo rompiera también estas
 * pruebas.
 */
export async function seedProduct(
  page: Page,
  name: string,
  priceCents: number,
  modifierGroupIds: string[] = [],
): Promise<string> {
  const { data } = await apiPost<{ data: { id: string } }>(page, '/api/v1/catalog/products', {
    name,
    price_cents: priceCents,
    prep_minutes: 5,
    modifier_group_ids: modifierGroupIds,
  })

  return data.id
}

/** El panel de la cuenta: lo que lleva el cliente. */
export function ticket(page: Page) {
  return page.getByRole('complementary', { name: 'La cuenta' })
}

/** La nota que se le entrega al cliente. */
export function note(page: Page) {
  return page.getByRole('dialog')
}
