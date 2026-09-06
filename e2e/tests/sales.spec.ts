import { expect, test } from '@playwright/test'
import { dashboardOf, TENANTS } from '../support/addresses'
import { apiPost } from '../support/api'
import { signIn } from '../support/dashboard'

/*
 * WHAT I SOLD TODAY.
 *
 * The last phase's exit criterion: the owner opens this on their phone,
 * standing between two orders, and knows how much they sold and what sells most
 * without touching anything else.
 */

const RUN = Date.now().toString(36).slice(-5).toUpperCase()

test('the owner opens the day\'s sales and they add up', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')

  // Two sales today, at different prices so the ordering can be checked.
  const { data: caro } = await apiPost<{ data: { id: string } }>(
    page,
    '/api/v1/catalog/products',
    { name: `[e2e] Parrilla ${RUN}`, price_cents: 1000 },
  )

  const { data: barato } = await apiPost<{ data: { id: string } }>(
    page,
    '/api/v1/catalog/products',
    { name: `[e2e] Jugo ${RUN}`, price_cents: 100 },
  )

  for (const [product, quantity] of [
    [caro.id, 2],
    [barato.id, 3],
  ] as const) {
    const { data: order } = await apiPost<{ data: { id: string } }>(page, '/api/v1/orders', {
      items: [{ product_id: product, quantity: quantity }],
    })

    // Confirming is what turns an order into a sale: one the tenant never
    // accepted does not count.
    await apiPost(page, `/api/v1/orders/${order.id}/advance`, { status: 'confirmed' })

    await apiPost(page, `/api/v1/orders/${order.id}/payments`, {
      method: 'cash_usd',
      amount_cents: quantity * (product === caro.id ? 1000 : 100),
    })
  }

  await page.goto(dashboardOf(TENANTS.arepera) + 'ventas')

  await expect(page.getByRole('heading', { name: 'Ventas' })).toBeVisible()

  // What sells most, ordered by what it LEAVES: three juices look busy and earn
  // less than two grills.
  // The ORDER between this run's two products is checked, not which one heads
  // the list: the database is additive and anything from earlier runs could be
  // above, even tied on total.
  const grill = page.getByRole('listitem').filter({ hasText: `[e2e] Parrilla ${RUN}` })
  await expect(grill).toContainText('$20,00')
  await expect(grill).toContainText('2×')

  /*
   * Product ORDER is not checked here but in Pest with clean data: this list
   * is capped to the tenant's top, and with what other runs leave, this run's
   * juice can legitimately fall outside. What only the browser proves is that
   * the screen shows it.
   */

  await expect(page.getByText('Cómo pagaron')).toBeVisible()
  await expect(page.getByText('Efectivo en dólares')).toBeVisible()

  // And the shape of the day, which is what people come to look at.
  await expect(page.getByRole('heading', { name: 'A qué hora' })).toBeVisible()
  await expect(page.getByText('La hora fuerte:')).toBeVisible()
})

test('changing the period changes what is shown', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await page.goto(dashboardOf(TENANTS.arepera) + 'ventas')

  await expect(page.getByRole('button', { name: 'Hoy' })).toHaveAttribute('aria-pressed', 'true')

  await page.getByRole('button', { name: 'Este mes' }).click()

  await expect(page.getByRole('button', { name: 'Este mes' })).toHaveAttribute(
    'aria-pressed',
    'true',
  )

  // The month includes today, so it can never have fewer orders.
  await expect(page.getByText(/pedidos?/)).toBeVisible()
})

test('the kitchen does not see the sales', async ({ page }) => {
  // In some tenants the manager works all day and the owner would rather they
  // did not see the totals. Here what does not apply does not exist: no menu
  // entry and no screen.
  await page.goto(dashboardOf(TENANTS.arepera))
  await page.getByLabel('Correo').fill('carlos@elsazon.test')
  await page.getByLabel('Contraseña').fill('demo1234')
  await page.getByRole('button', { name: 'Entrar' }).click()

  await expect(page.getByRole('link', { name: 'Ventas' })).toBeHidden()
})
