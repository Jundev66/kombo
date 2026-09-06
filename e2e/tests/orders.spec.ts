import { expect, test } from '@playwright/test'
import { dashboardOf, TENANTS } from '../support/addresses'
import { apiFetch, apiPost } from '../support/api'
import { signIn } from '../support/dashboard'

/*
 * An order's whole journey, through the browser.
 *
 * The phase's exit criterion: placed → confirmed → in the kitchen → ready →
 * delivered, pressing the buttons a person would press.
 */

const RUN = Date.now().toString(36).slice(-5).toUpperCase()

test.beforeEach(async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
})

/** Leaves a product on the menu and returns its id. */
async function aProduct(page: import('@playwright/test').Page, name: string): Promise<string> {
  const { data } = await apiPost<{ data: { id: string } }>(page, '/api/v1/catalog/products', {
    name: name,
    price_cents: 300,
    prep_minutes: 8,
  })

  return data.id
}

/** Leaves a placed order and returns its number. */
async function anOrder(page: import('@playwright/test').Page, productId: string): Promise<number> {
  const { data } = await apiPost<{ data: { number: number } }>(page, '/api/v1/orders', {
    items: [{ product_id: productId, quantity: 2 }],
    service_type: 'takeaway',
    customer_name: `[e2e] Cliente ${RUN}`,
  })

  return data.number
}

test('an order travels the board through to delivered', async ({ page }) => {
  const product = await aProduct(page, `[e2e] Arepa ${RUN}`)
  const number = await anOrder(page, product)

  await page.goto(dashboardOf(TENANTS.arepera) + 'pedidos')

  const card = page.getByRole('listitem').filter({ hasText: `#${number}` })

  await expect(card).toBeVisible()
  await expect(card).toContainText('Sin confirmar')
  await expect(card).toContainText('2× [e2e] Arepa')

  // Each step is one button, and the button says what will happen.
  await card.getByRole('button', { name: 'Confirmar' }).click()
  await expect(card).toContainText('Confirmado')

  await card.getByRole('button', { name: 'A la cocina' }).click()
  await expect(card).toContainText('En la cocina')

  await card.getByRole('button', { name: 'Listo' }).click()
  await expect(card).toContainText('Listo')

  await card.getByRole('button', { name: 'Entregado' }).click()

  // Delivered leaves the board: it is for working, not for browsing history.
  await expect(page.getByRole('listitem').filter({ hasText: `#${number}` })).toBeHidden()
})

test('the server computes the total, not the screen', async ({ page }) => {
  // Asserting the CAUSE as well as the symptom. Two arepas at 3.00 are 6.00 —
  // if the screen added it up itself, it would agree until the day it did not.
  const product = await aProduct(page, `[e2e] Arepa total ${RUN}`)
  const number = await anOrder(page, product)

  await page.goto(dashboardOf(TENANTS.arepera) + 'pedidos')

  const card = page.getByRole('listitem').filter({ hasText: `#${number}` })
  await expect(card).toContainText('$6,00')

  const { data } = await apiFetch<{ data: Array<{ number: number; totalCents: number }> }>(
    page,
    '/api/v1/orders?open=1',
  )

  expect(data.find((o) => o.number === number)?.totalCents).toBe(600)
})

test('payment comes in a mix, and mobile payment waits for somebody to confirm it', async ({ page }) => {
  const product = await aProduct(page, `[e2e] Arepa cobro ${RUN}`)
  const number = await anOrder(page, product)

  await page.goto(dashboardOf(TENANTS.arepera) + 'pedidos')
  await page.getByRole('listitem').filter({ hasText: `#${number}` }).getByText(`#${number}`).click()

  // Half in cash.
  await page.getByLabel('Método de pago').selectOption('cash_usd')
  await page.getByLabel('Cuánto pagó').fill('3,00')
  await page.getByRole('button', { name: 'Registrar el pago' }).click()

  await expect(page.getByText('Falta $3,00')).toBeVisible()

  // The other half by mobile transfer: it arrives pending review.
  await page.getByLabel('Método de pago').selectOption('pago_movil')
  await page.getByLabel('Cuánto pagó').fill('3,00')
  await page.getByLabel('Referencia').fill('004512')
  await page.getByRole('button', { name: 'Registrar el pago' }).click()

  // Still owing: mobile payment does not count until somebody looks at the
  // receipt and says yes. There is no banking API to ask.
  await expect(page.getByText('Falta $3,00')).toBeVisible()

  // "Confirm the payment", not "Confirm": this same screen has a button that
  // confirms the ORDER, and they are different things. The name disambiguates
  // for the test and, above all, for whoever presses it.
  await page.getByRole('button', { name: 'Confirmar el pago' }).click()

  await expect(page.getByText('Falta $3,00')).toBeHidden()
  await expect(page.getByText('Ref. 004512')).toBeVisible()
})

test('cancelling requires a reason', async ({ page }) => {
  const product = await aProduct(page, `[e2e] Arepa cancelada ${RUN}`)
  const number = await anOrder(page, product)

  await page.goto(dashboardOf(TENANTS.arepera) + 'pedidos')
  await page.getByRole('listitem').filter({ hasText: `#${number}` }).getByText(`#${number}`).click()

  // Without a reason the button does not let you: cancelling is the natural way
  // to get food out unpaid, and at month end somebody will ask why there were
  // fourteen.
  const botón = page.getByRole('button', { name: 'Cancelar el pedido' })
  await expect(botón).toBeDisabled()

  await page.getByLabel('Motivo').fill('El cliente se arrepintió')
  await expect(botón).toBeEnabled()
  await botón.click()

  await expect(page.getByRole('listitem').filter({ hasText: `#${number}` })).toBeHidden()
})
