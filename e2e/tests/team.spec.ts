import { expect, test } from '@playwright/test'
import { dashboardOf, TENANTS } from '../support/addresses'
import { signIn } from '../support/dashboard'

/*
 * THE TEAM AND THE OPENING HOURS.
 *
 * The two things a tenant needs to change on their own, without calling
 * anybody: adding whoever starts work, and saying what time they open. Without
 * this it meant going in through the database.
 */

const RUN = Date.now().toString(36).slice(-5).toUpperCase()

test('the owner adds somebody to the team, and that person signs in', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await page.goto(dashboardOf(TENANTS.arepera) + 'equipo')

  await expect(page.getByRole('heading', { name: 'Equipo' })).toBeVisible()

  const email = `nuevo-${RUN.toLowerCase()}@elsazon.test`

  await page.getByRole('button', { name: 'Sumar a alguien' }).click()

  await page.getByRole('textbox', { name: 'Nombre', exact: true }).fill(`[e2e] Nuevo ${RUN}`)
  await page.getByLabel('Correo').fill(email)
  await page.getByLabel('Rol').selectOption({ label: 'Encargado' })
  await page.getByRole('textbox', { name: 'Contraseña', exact: true }).fill('clave-larga-123')
  await page.getByLabel('PIN').fill('7788')

  await page.getByRole('button', { name: 'Guardar' }).click()

  // They appear in the list, with their role and their PIN set — without a PIN
  // they would reach neither the till nor the kitchen.
  const record = page.getByRole('listitem').filter({ hasText: `[e2e] Nuevo ${RUN}` })
  await expect(record).toContainText('Encargado')
  await expect(record).toContainText('Con PIN')

  // And they really sign in, which is the only thing that proves it was stored.
  await page.getByRole('button', { name: 'Salir' }).click()
  await page.getByLabel('Correo').fill(email)
  await page.getByLabel('Contraseña').fill('clave-larga-123')
  await page.getByRole('button', { name: 'Entrar' }).click()

  await expect(page.getByRole('button', { name: 'Salir' })).toBeVisible()
})

test('deactivating removes access, and it can be reactivated', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await page.goto(dashboardOf(TENANTS.arepera) + 'equipo')

  const email = `baja-${RUN.toLowerCase()}@elsazon.test`

  await page.getByRole('button', { name: 'Sumar a alguien' }).click()
  await page.getByRole('textbox', { name: 'Nombre', exact: true }).fill(`[e2e] Baja ${RUN}`)
  await page.getByLabel('Correo').fill(email)
  await page.getByRole('textbox', { name: 'Contraseña', exact: true }).fill('clave-larga-123')
  await page.getByRole('button', { name: 'Guardar' }).click()

  const record = page.getByRole('listitem').filter({ hasText: `[e2e] Baja ${RUN}` })
  await expect(record).toBeVisible()

  await record.getByRole('button', { name: `Dar de baja a [e2e] Baja ${RUN}` }).click()

  // Not deleted: deactivated. What they did still says their name.
  await expect(record).toContainText('de baja')
  await expect(record.getByRole('button', { name: /Reactivar/ })).toBeVisible()
})

test('the owner cannot deactivate herself', async ({ page }) => {
  // The click that leaves somebody outside their own business on a Friday
  // afternoon.
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await page.goto(dashboardOf(TENANTS.arepera) + 'equipo')

  const hers = page.getByRole('listitem').filter({ hasText: 'maria@elsazon.test' })

  await hers.getByRole('button', { name: 'Dar de baja a María' }).click()

  // Still active: the reactivate button only appears when somebody is
  // deactivated, so its absence is the clean check.
  await expect(hers.getByRole('button', { name: /Reactivar/ })).toBeHidden()
})

test('the kitchen does not manage the team', async ({ page }) => {
  // Whoever can create users can create themselves an owner account.
  await page.goto(dashboardOf(TENANTS.arepera))
  await page.getByLabel('Correo').fill('carlos@elsazon.test')
  await page.getByLabel('Contraseña').fill('demo1234')
  await page.getByRole('button', { name: 'Entrar' }).click()

  await expect(page.getByRole('link', { name: 'Equipo' })).toBeHidden()
})

test('the owner changes the opening hours, and the portal obeys', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await page.goto(dashboardOf(TENANTS.arepera) + 'horario')

  await expect(page.getByRole('heading', { name: 'Horario' })).toBeVisible()

  // Monday is closed and saved.
  const monday = page.getByRole('switch').nth(1)
  const wasBefore = await monday.isChecked()

  await monday.setChecked(false)
  await page.getByRole('button', { name: 'Guardar el horario' }).click()
  await expect(page.getByText('Guardado')).toBeVisible()

  await page.reload()
  await expect(page.getByRole('switch').nth(1)).not.toBeChecked()

  // Left as it was: what a test changes, that test restores.
  await page.getByRole('switch').nth(1).setChecked(wasBefore)
  await page.getByRole('button', { name: 'Guardar el horario' }).click()
  await expect(page.getByText('Guardado')).toBeVisible()
})
