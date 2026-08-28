import { expect, type Page } from '@playwright/test'
import { PASSWORD, panelOf } from './addresses'

/**
 * Entrar al panel de un negocio.
 *
 * Selectores por rol y por etiqueta, nunca por clase ni `data-testid`: si un
 * control no se alcanza así, el arreglo está en el componente y no en la
 * prueba. Y `getByLabel` antes que un selector de CSS porque es exactamente
 * lo que hace una persona: buscar el campo que dice «Correo».
 */
export async function signIn(
  page: Page,
  tenant: string,
  email: string,
  password: string = PASSWORD,
): Promise<void> {
  await page.goto(panelOf(tenant))

  await page.getByLabel('Correo').fill(email)
  await page.getByLabel('Contraseña').fill(password)
  await page.getByRole('button', { name: 'Entrar' }).click()

  // Se espera algo que sólo existe DESPUÉS de entrar, y no un texto de una
  // pantalla concreta: cada rol podría aterrizar en un sitio distinto.
  await expect(page.getByRole('button', { name: 'Salir' })).toBeVisible()
}
