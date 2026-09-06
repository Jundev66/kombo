import { expect, test } from '@playwright/test'
import { kdsOf, dashboardOf, PASSWORD, TENANTS } from '../support/addresses'
import { apiFetch, apiPost } from '../support/api'
import { clearBoard, kitchenTicket, enterKitchen } from '../support/kds'
import { signIn, signOut } from '../support/dashboard'

/*
 * THE JOURNEY THAT MOTIVATED THE PROJECT.
 *
 * Confirming an order in the dashboard makes the ticket appear by itself on the
 * kitchen screen; marking it ready takes it off. Two different screens, on two
 * different machines, with nobody telling anybody.
 */

const RUN = Date.now().toString(36).slice(-5).toUpperCase()

async function aConfirmedOrder(
  page: import('@playwright/test').Page,
  name: string,
  modifiers: string[] = [],
): Promise<number> {
  const { data: product } = await apiPost<{ data: { id: string } }>(
    page,
    '/api/v1/catalog/products',
    { name: name, price_cents: 300, prep_minutes: 8 },
  )

  const { data: order } = await apiPost<{ data: { id: string; number: number } }>(
    page,
    '/api/v1/orders',
    {
      items: [{ product_id: product.id, quantity: 2, modifier_ids: modifiers }],
      service_type: 'takeaway',
    },
  )

  await apiPost(page, `/api/v1/orders/${order.id}/advance`, { status: 'confirmed' })

  return order.number
}

test('confirming in the dashboard makes the ticket appear in the kitchen', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await clearBoard(page)
  const number = await aConfirmedOrder(page, `[e2e] Arepa cocina ${RUN}`)

  // Another screen, ANOTHER SESSION. Closing the dashboard's is not tidiness:
  // Sanctum prefers the cookie over the token, so without this the test types
  // Carlos's PIN and operates as María — passing green exactly where it should
  // have caught a missing permission.
  await signOut(page)
  await enterKitchen(page, TENANTS.arepera, 'Carlos', '4567')

  const card = kitchenTicket(page, number)

  await expect(card).toBeVisible()
  await expect(card).toContainText('2× [e2e] Arepa cocina')
  await expect(card).toContainText('Para llevar')
})

test('the ticket advances with one tap and finally leaves the screen', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await clearBoard(page)
  const number = await aConfirmedOrder(page, `[e2e] Arepa avanza ${RUN}`)

  await signOut(page)
  await enterKitchen(page, TENANTS.arepera, 'Carlos', '4567')

  const card = kitchenTicket(page, number)

  // The button says what will happen, not the state it goes to.
  await card.getByRole('button', { name: 'Empezar' }).click()
  await expect(card.getByRole('button', { name: 'Listo' })).toBeVisible()

  await card.getByRole('button', { name: 'Listo' }).click()
  await expect(card.getByRole('button', { name: 'Entregado' })).toBeVisible()

  await card.getByRole('button', { name: 'Entregado' }).click()

  // It leaves the kitchen: history is reports' business, and a screen showing
  // yesterday is a screen nobody looks at.
  await expect(kitchenTicket(page, number)).toBeHidden()
})

test('add-ons are read on the ticket, with nothing to look up', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await clearBoard(page)

  await apiPost(page, '/api/v1/catalog/modifier-groups', {
    name: `[e2e] Extras cocina ${RUN}`,
    modifiers: [{ name: `Sin cebolla ${RUN}` }],
  })

  // The group is created with its options in one go, but the modifier's id is
  // needed to order it. It is asked for rather than inferred.
  const groups = await apiFetch<{
    data: Array<{ modifiers: Array<{ id: string; name: string }> }>
  }>(page, '/api/v1/catalog/modifier-groups')

  const noOnion = groups.data
    .flatMap((g) => g.modifiers)
    .find((m) => m.name === `Sin cebolla ${RUN}`)

  expect(noOnion).toBeDefined()

  const number = await aConfirmedOrder(
    page,
    `[e2e] Arepa agregados ${RUN}`,
    [noOnion!.id],
  )

  await signOut(page)
  await enterKitchen(page, TENANTS.arepera, 'Carlos', '4567')

  // As TEXT, ready to read while cooking. Not an id that would have to be
  // looked up with your hands full.
  await expect(kitchenTicket(page, number)).toContainText(`Sin cebolla ${RUN}`)
})

test('the cook only has the kitchen: they do not reach the dashboard', async ({ page }) => {
  // Carlos reaches his screen and nothing else. What does not apply does not
  // exist. There is no dashboard session to close here: the kitchen is direct.
  await enterKitchen(page, TENANTS.arepera, 'Carlos', '4567')
  await expect(page.getByRole('heading', { name: 'Cocina' })).toBeVisible()

  await page.goto(dashboardOf(TENANTS.arepera))

  // The dashboard uses a cookie session rather than the tablet's token: it asks.
  await expect(page.getByRole('button', { name: 'Entrar' })).toBeVisible()
})

test('the wrong PIN does not open the kitchen', async ({ page }) => {
  await page.goto(kdsOf(TENANTS.arepera))

  if (await page.getByRole('button', { name: 'Dar de alta' }).isVisible()) {
    await page.getByLabel('Correo').fill('maria@elsazon.test')
    await page.getByLabel('Contraseña').fill(PASSWORD)
    await page.getByRole('button', { name: 'Dar de alta' }).click()
  }

  await page.getByRole('button', { name: 'Carlos' }).click()

  for (const digit of '0000') {
    await page.getByRole('button', { name: digit, exact: true }).click()
  }

  await expect(page.getByRole('alert')).toContainText('Ese PIN no es')
})
