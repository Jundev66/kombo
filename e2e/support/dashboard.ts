import { expect, type Page } from '@playwright/test'
import { PASSWORD, dashboardOf } from './addresses'

/**
 * Signing into a tenant's dashboard.
 *
 * Selectors by role and by label, never by class or `data-testid`: if a control
 * cannot be reached that way, the fix belongs in the component and not in the
 * test. And `getByLabel` before a CSS selector, because that is exactly what a
 * person does — look for the field that says "Correo".
 */
export async function signIn(
  page: Page,
  tenant: string,
  email: string,
  password: string = PASSWORD,
): Promise<void> {
  await page.goto(dashboardOf(tenant))

  await page.getByLabel('Correo').fill(email)
  await page.getByLabel('Contraseña').fill(password)
  await page.getByRole('button', { name: 'Entrar' }).click()

  // It waits for something that only exists AFTER signing in, rather than text
  // from a specific screen: each role may land somewhere different.
  await expect(page.getByRole('button', { name: 'Salir' })).toBeVisible()
}

/**
 * Signing out of the dashboard.
 *
 * Needed more than it looks: the kitchen and the till sign in with a token, but
 * Sanctum prefers the browser session over the token when both are present. A
 * test that seeds the catalog as the owner and then enters the till with the
 * counter's PIN would be operating as the owner without knowing, and would pass
 * green exactly where it should have caught a missing permission.
 */
export async function signOut(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Salir' }).click()
  await expect(page.getByRole('button', { name: 'Entrar' })).toBeVisible()
}
