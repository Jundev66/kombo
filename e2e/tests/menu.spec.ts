import { expect, test } from '@playwright/test'
import { dashboardOf, TENANTS } from '../support/addresses'
import { apiFetch } from '../support/api'
import { signIn } from '../support/dashboard'

/*
 * Filling in the menu from the dashboard, as an owner would on their phone.
 *
 * The phase's exit criterion: if this cannot be done, there is nothing to sell
 * and nothing to send to the kitchen.
 */

// What this run creates is marked: seeding is ADDITIVE and deletes nothing,
// so without a marker the second run would find the first run's products.
const RUN = Date.now().toString(36).slice(-5).toUpperCase()

test.beforeEach(async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
})

test('the owner adds a product to the menu', async ({ page }) => {
  const name = `[e2e] Reina Pepiada ${RUN}`

  await page.goto(dashboardOf(TENANTS.arepera) + 'carta/nuevo')

  await page.getByLabel('Nombre').fill(name)
  await page.getByLabel('Precio en dólares').fill('3,50')
  await page.getByLabel('Minutos que tarda').fill('8')
  await page.getByRole('button', { name: 'Guardar' }).click()

  // Back to the menu, and the product is there with its price.
  // SEARCHED for rather than looked up in the whole list: the menu is
  // paginated, and a test expecting its own on the first page stops passing
  // once the tenant has more than fifty products.
  await page.getByRole('searchbox', { name: 'Buscar en la carta' }).fill(name)

  const found = page.getByRole('listitem').filter({ hasText: name })
  await expect(found).toBeVisible()
  await expect(found).toContainText('$3,50')
})

test('the price is stored in cents, not in floating point', async ({ page }) => {
  // Asserting the CAUSE as well as the symptom: the screen saying "$3,50" does
  // not prove it was stored correctly. 3.5 in floating point ends in a cash
  // count that does not balance three months later.
  const name = `[e2e] Tequeños ${RUN}`

  await page.goto(dashboardOf(TENANTS.arepera) + 'carta/nuevo')
  await page.getByLabel('Nombre').fill(name)
  await page.getByLabel('Precio en dólares').fill('3,50')
  await page.getByRole('button', { name: 'Guardar' }).click()

  await page.getByRole('searchbox', { name: 'Buscar en la carta' }).fill(name)
  await expect(page.getByRole('listitem').filter({ hasText: name })).toBeVisible()

  const { data } = await apiFetch<{ data: Array<{ name: string; priceCents: number }> }>(
    page,
    '/api/v1/catalog/products?search=Teque',
  )

  const created = data.find((p) => p.name === name)
  expect(created?.priceCents).toBe(350)
})

test('an empty menu says what to do, not just that it is empty', async ({ page }) => {
  // An empty list that only says "no products" leaves somebody hunting for
  // where they are created. Checked on the tenant with no menu loaded.
  await signIn(page, TENANTS.pizzeria, 'pedro@laesquina.test')
  await page.goto(dashboardOf(TENANTS.pizzeria) + 'carta')

  await expect(page.getByRole('link', { name: /Añadir el primero/i })).toBeVisible()
})

test('an add-on group is created with its options in one go', async ({ page }) => {
  // A group with no options is a question with no answers on the menu.
  await page.goto(dashboardOf(TENANTS.arepera) + 'agregados')

  // `exact: true` on the options: getByLabel matches PARTIALLY and
  // case-insensitively, so "Opción 1" would also find "Precio de la opción 1".
  // The classic trap of names that contain one another.

  await page.getByLabel('La pregunta').fill(`[e2e] Extras ${RUN}`)
  await page.getByLabel('Opción 1', { exact: true }).fill('Sin cebolla')
  await page.getByRole('button', { name: 'Otra opción' }).click()
  await page.getByLabel('Opción 2', { exact: true }).fill('Extra queso')
  await page.getByLabel('Precio de la opción 2').fill('0,50')
  await page.getByRole('button', { name: 'Guardar el grupo' }).click()

  // Scoped to THIS run's group. Seeding is additive and deletes nothing, so a
  // bare "Sin cebolla" would also match earlier runs' and Playwright would fail
  // on ambiguity — its way of saying the test was badly written, not that the
  // system is broken.
  const group = page.getByRole('listitem').filter({ hasText: `[e2e] Extras ${RUN}` })

  await expect(group).toBeVisible()
  await expect(group.getByText('Sin cebolla')).toBeVisible()
  await expect(group.getByText('Extra queso $0,50')).toBeVisible()
})

test('the rate of the day is loaded and shown applied', async ({ page }) => {
  await page.goto(dashboardOf(TENANTS.arepera) + 'tasa')

  await page.getByLabel('Bolívares por dólar').fill('36,50')
  await page.getByRole('button', { name: 'Guardar la tasa' }).click()

  await expect(page.getByText('Bs 36,5 por dólar')).toBeVisible()

  // And the check with a real amount: $100 at 36.50 is Bs 3.650,00. A bare
  // "36,5" does not reveal an extra zero; this does.
  await expect(page.getByText('Bs 3.650,00')).toBeVisible()
})

test('with no rate loaded, the menu warns before it becomes a problem', async ({ page }) => {
  // The pizzeria has not loaded a rate. Discovering that with a customer
  // waiting is too late.
  await signIn(page, TENANTS.pizzeria, 'pedro@laesquina.test')
  await page.goto(dashboardOf(TENANTS.pizzeria) + 'carta')

  await expect(page.getByRole('alert')).toContainText('tasa del día')
})

/*
 * THE MENU DOES NOT TRUNCATE SILENTLY.
 *
 * The failure that motivated the review: the demo tenant has hundreds of
 * products and the screen showed the first page's fifty with no number, no
 * button and nothing to suggest it. The server already sent `meta.total`; it was
 * lost in one line of the client.
 *
 * Truncating silently is the worst failure a list can have: whoever looks does
 * not know anything is missing, so they do not go looking.
 */
test('the menu says how many products there are and lets you see the rest', async ({ page }) => {
  await page.goto(dashboardOf(TENANTS.arepera) + 'carta')

  const { meta } = await apiFetch<{ meta: { total: number; lastPage: number } }>(
    page,
    '/api/v1/catalog/products?include_inactive=1',
  )

  // The test only makes sense with more products than fit on a page. The demo
  // seed always has them.
  expect(meta.lastPage).toBeGreaterThan(1)

  // The total, at the top and visible. `exact` because the list footer says
  // "Se ven 50 de 695 productos", which contains this same text.
  await expect(page.getByText(`${meta.total} productos`, { exact: true })).toBeVisible()

  const firstBatch = await page.getByRole('listitem').count()

  // And the footer says how many of how many are visible, rather than nothing.
  await expect(page.getByText(`Se ven ${firstBatch} de ${meta.total} productos`)).toBeVisible()

  await page.getByRole('button', { name: /Ver \d+ más/ }).click()

  // "See more" really brings more: the list grows and the footer reflects it.
  await expect
    .poll(() => page.getByRole('listitem').count())
    .toBeGreaterThan(firstBatch)
})

test('a search with no results does not say the menu is empty', async ({ page }) => {
  // Two different things that said the same: searching for something that does
  // not exist answered "Your menu is empty · Add what you sell" with a button
  // to add the first, in front of an owner with hundreds of products loaded.
  await page.goto(dashboardOf(TENANTS.arepera) + 'carta')

  await page.getByRole('searchbox', { name: 'Buscar en la carta' }).fill('zzz-no-existe-zzz')

  await expect(page.getByText(/Nada que se llame/)).toBeVisible()
  await expect(page.getByText('Tu carta está vacía')).toBeHidden()
})
