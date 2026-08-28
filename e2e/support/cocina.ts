import { expect, type Page } from '@playwright/test'
import { cocinaOf, PASSWORD } from './addresses'

/**
 * Entrar a la pantalla de cocina, por sus dos puertas.
 *
 * Se recorren las dos de verdad y no se usa ningún atajo: son exactamente las
 * que se usan en un local, y probar un atajo dejaría sin cubrir lo único que
 * hay que probar.
 */
export async function enterKitchen(
  page: Page,
  tenant: string,
  cocinero: string,
  pin: string,
  altaCon = 'maria@elsazon.test',
): Promise<void> {
  await page.goto(cocinaOf(tenant))

  // Primera puerta: dar de alta la tablet. Sólo la primera vez en su vida,
  // pero cada prueba arranca con un navegador limpio.
  if (await page.getByRole('button', { name: 'Dar de alta' }).isVisible()) {
    await page.getByLabel('Correo').fill(altaCon)
    await page.getByLabel('Contraseña').fill(PASSWORD)
    await page.getByRole('button', { name: 'Dar de alta' }).click()
  }

  // Segunda puerta: quién está en la cocina.
  await expect(page.getByRole('heading', { name: '¿Quién está en la cocina?' })).toBeVisible()
  await page.getByRole('button', { name: cocinero }).click()

  // El PIN se envía solo al cuarto dígito: no hay botón de confirmar, porque
  // un toque de más con las manos ocupadas es un toque de más.
  for (const digit of pin) {
    await page.getByRole('button', { name: digit, exact: true }).click()
  }

  await expect(page.getByRole('heading', { name: 'Cocina' })).toBeVisible()
}

/** La tarjeta de una comanda concreta, por su número. */
export function comanda(page: Page, numero: number) {
  return page.getByRole('listitem').filter({ hasText: `#${numero}` })
}
