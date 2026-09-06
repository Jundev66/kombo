import { expect, test } from '@playwright/test'
import { dashboardOf, TENANTS } from '../support/addresses'
import { apiFetch, apiPost } from '../support/api'
import { signIn } from '../support/dashboard'

/*
 * DELIVERY, CUSTOMERS AND EXPORT.
 *
 * The last three things the plan was missing: whoever carries the food, the
 * book of who buys, and getting the orders out into a spreadsheet.
 */

const RUN = Date.now().toString(36).slice(-5).toUpperCase()

/** A delivery order, ready for somebody to go out with. */
async function aReadyDelivery(
  page: import('@playwright/test').Page,
): Promise<{ id: string; number: number }> {
  const { data: product } = await apiPost<{ data: { id: string } }>(
    page,
    '/api/v1/catalog/products',
    { name: `[e2e] Reparto ${RUN}`, price_cents: 500 },
  )

  const { data: zones } = await apiFetch<{ data: { id: string; name: string }[] }>(
    page,
    '/api/v1/delivery/zones',
  )

  const { data: order } = await apiPost<{ data: { id: string; number: number } }>(
    page,
    '/api/v1/orders',
    {
      items: [{ product_id: product.id, quantity: 1 }],
      service_type: 'delivery',
      customer_name: `Cliente ${RUN}`,
      customer_phone: `0414${Date.now().toString().slice(-7)}`,
      delivery_address: `Cuarta avenida, casa ${RUN}`,
      delivery_zone_id: zones[0]?.id,
    },
  )

  for (const status of ['confirmed', 'preparing', 'ready']) {
    await apiPost(page, `/api/v1/orders/${order.id}/advance`, { status: status })
  }

  return order
}

test('the courier takes an order, goes out and delivers it', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  const order = await aReadyDelivery(page)

  await page.goto(dashboardOf(TENANTS.arepera) + 'entregas')

  await expect(page.getByRole('heading', { name: 'Entregas' })).toBeVisible()

  const card = page.getByText(`Cuarta avenida, casa ${RUN}`).locator('../..')

  // What to collect on arrival: 5.00 for the dish plus delivery. It is the only
  // thing the courier needs to know about the money.
  await expect(card).toContainText('Cobrar')

  await card.getByRole('button', { name: 'Lo llevo yo' }).click()

  // It moves to "what I am carrying", and goes out from there.
  await expect(page.getByRole('button', { name: 'Salgo con él' })).toBeVisible()
  await page.getByRole('button', { name: 'Salgo con él' }).click()

  await page.getByRole('button', { name: 'Entregado' }).click()

  // It leaves the list: no longer anybody's business.
  await expect(page.getByText(`Cuarta avenida, casa ${RUN}`)).toBeHidden()

  /*
   * And it ends delivered. THAT order is queried rather than searched for in
   * the list: the board is capped, and with earlier runs' history ahead of it
   * this run's would legitimately fall outside.
   */
  const { data: delivered } = await apiFetch<{ data: { status: string } }>(
    page,
    `/api/v1/orders/${order.id}`,
  )

  expect(delivered.status).toBe('delivered')
})

test('the customer book fills itself in', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')

  const phone = `0414${Date.now().toString().slice(-7)}`

  const { data: product } = await apiPost<{ data: { id: string } }>(
    page,
    '/api/v1/catalog/products',
    { name: `[e2e] Cliente ${RUN}`, price_cents: 300 },
  )

  // Two orders from the same number: one record with two orders.
  for (const attemptIndex of [1, 2]) {
    await apiPost(page, '/api/v1/orders', {
      items: [{ product_id: product.id, quantity: attemptIndex }],
      customer_name: `Doña ${RUN}`,
      customer_phone: phone,
    })
  }

  await page.goto(dashboardOf(TENANTS.arepera) + 'clientes')

  await page.getByLabel('Buscar').fill(phone)

  const record = page.getByRole('listitem').filter({ hasText: `Doña ${RUN}` })
  await expect(record).toContainText('2 pedidos')

  // The note is the only thing written by hand, and what makes the book worth
  // having.
  await record.getByRole('button', { name: `Doña ${RUN}` }).click()

  await page.getByLabel('Nota').fill('No le pongan cebolla')
  await page.getByRole('button', { name: 'Guardar la nota' }).click()

  await page.reload()
  await page.getByLabel('Buscar').fill(phone)
  await page.getByRole('button', { name: `Doña ${RUN}` }).click()

  await expect(page.getByLabel('Nota')).toHaveValue('No le pongan cebolla')
})

test('the sales export to a file', async ({ page }) => {
  // "A suspended tenant reads and exports" is written in the middleware; this
  // is what makes it true.
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await page.goto(dashboardOf(TENANTS.arepera) + 'ventas')

  const download = page.waitForEvent('download')

  await page.getByRole('link', { name: 'Exportar' }).click()

  const file = await download
  expect(file.suggestedFilename()).toContain('pedidos-elsazon')
})
