import { expect, test } from '@playwright/test'
import { dashboardOf, TENANTS } from '../support/addresses'
import { apiFetch, apiPost } from '../support/api'
import { signIn } from '../support/dashboard'

/*
 * Signing into the dashboard, through the browser and end to end.
 *
 * It walks what no PHP test can walk alone: the CSRF cookie, the session over
 * the right subdomain, and the screen painting what the server said and nothing
 * else.
 */

test('the sign-in screen shows the TENANT\'s name, not the platform\'s', async ({ page }) => {
  // The reason /me answers without a session. A login saying "Kombo" instead of
  // "Arepera El Sazón" sows doubt about whether you are where you think.
  await page.goto(dashboardOf(TENANTS.arepera))

  await expect(page.getByRole('heading', { name: 'Arepera El Sazón' })).toBeVisible()
})

test('each tenant has its own sign-in screen', async ({ page }) => {
  await page.goto(dashboardOf(TENANTS.pizzeria))

  await expect(page.getByRole('heading', { name: 'Pizzería La Esquina' })).toBeVisible()
})

test('the owner signs in and sees her tenant and her name', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')

  // In the shell's header, not a page heading: who you are and where you are
  // have to be visible from any screen, not just the first.
  await expect(page.getByText('Arepera El Sazón')).toBeVisible()
  await expect(page.getByText('María')).toBeVisible()
})

test('the wrong password does not get in', async ({ page }) => {
  await page.goto(dashboardOf(TENANTS.arepera))

  await page.getByLabel('Correo').fill('maria@elsazon.test')
  await page.getByLabel('Contraseña').fill('la-que-no-es')
  await page.getByRole('button', { name: 'Entrar' }).click()

  await expect(page.getByRole('alert')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Salir' })).toBeHidden()
})

test('the server decides the menu, not a list written in React', async ({ page }) => {
  // Asserting the CAUSE as well as the symptom: a test that only looked at the
  // screen would pass green with a hand-painted menu.
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')

  const capabilities = await apiFetch<{
    user: { name: string }
    permissions: string[]
  }>(page, '/api/v1/me')

  expect(capabilities.user.name).toBe('María')

  const nav = page.getByRole('navigation', { name: 'Secciones' }).first()

  // The owner can manage the menu, so she sees it in the bar.
  expect(capabilities.permissions).toContain('catalog.view')
  await expect(nav.getByRole('link', { name: 'Carta' })).toBeVisible()

  // And the settings sit under "More", grouped. Three fit in the bar, and
  // filling it with what is touched once a month would hide what is used all
  // day — which was exactly the flat twelve-entry list's problem.
  expect(capabilities.permissions).toContain('settings.manage')

  await page.getByRole('button', { name: 'Más' }).first().click()
  await expect(page.getByRole('link', { name: 'Tasa' })).toBeVisible()
})

test('the kitchen does not see the sections that are not its own', async ({ page }) => {
  // Carlos only has the ticket board. No menu and no rate: what does not apply
  // DOES NOT EXIST, it does not appear greyed out.
  await signIn(page, TENANTS.arepera, 'carlos@elsazon.test')

  const nav = page.getByRole('navigation', { name: 'Secciones' }).first()

  await expect(nav.getByRole('link', { name: 'Carta' })).toBeHidden()
  await expect(nav.getByRole('link', { name: 'Tasa' })).toBeHidden()
})

test('signing out really leaves the session closed', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await page.getByRole('button', { name: 'Salir' }).click()

  await expect(page.getByRole('button', { name: 'Entrar' })).toBeVisible()

  // And the server does not believe anybody is inside either.
  const capabilities = await apiFetch<{ user: unknown }>(page, '/api/v1/me')
  expect(capabilities.user).toBeNull()
})

test('signing out right after touching something does NOT leave the session open', async ({ page }) => {
  /*
   * The rare case, and the one that bites at the counter.
   *
   * Every Laravel response carries its session cookie. A read that left BEFORE
   * signing out but arrives AFTER carries the previous session's cookie, the
   * browser applies it, and the sign-out is undone: the screen stays inside
   * with the name of whoever just left. On a machine three people share per
   * shift, that is somebody else's session.
   *
   * It is provoked the way it really happens: confirming an order leaves the
   * board revalidating and the "Sign out" click lands on top.
   */
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')

  const { data: product } = await apiPost<{ data: { id: string } }>(
    page,
    '/api/v1/catalog/products',
    { name: `[e2e] Salida ${Date.now().toString(36)}`, price_cents: 300 },
  )

  await apiPost(page, '/api/v1/orders', { items: [{ product_id: product.id, quantity: 1 }] })

  await page.goto(dashboardOf(TENANTS.arepera) + 'pedidos')

  // Confirming triggers the board's revalidation; the sign-out click lands on
  // top without waiting for it to finish.
  await page.getByRole('button', { name: 'Confirmar' }).first().click()
  await page.getByRole('button', { name: 'Salir' }).click()

  await expect(page.getByRole('button', { name: 'Entrar' })).toBeVisible()

  // And the server does not believe anybody is inside. If any of those reads
  // had returned the old cookie, María would show up here.
  const capabilities = await apiFetch<{ user: unknown }>(page, '/api/v1/me')
  expect(capabilities.user).toBeNull()
})
