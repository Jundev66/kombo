import { expect, test } from '@playwright/test'
import { TENANTS } from '../support/addresses'
import { apiPost } from '../support/api'
import { clearBoard, kitchenTicket, enterKitchen } from '../support/kds'
import { signIn, signOut } from '../support/dashboard'
import { addToCart, cartBar, openMenu, trackAddress } from '../support/portal'

/*
 * A WHOLE ORDER FROM A PHONE, WITH NO ACCOUNT.
 *
 * The phase's exit criterion: somebody arriving from a WhatsApp link looks at
 * the menu, builds their order, sends it, and that ticket appears by itself in
 * the kitchen.
 *
 * The portal tests sign into nothing: the person on the other side is somebody
 * off the street. When the menu has to be seeded, the dashboard is entered,
 * seeded, and LEFT before touching the portal.
 */

const RUN = Date.now().toString(36).slice(-5).toUpperCase()

test('an order from a phone reaches the kitchen, with no account', async ({ page }) => {
  const name = `[e2e] Arepa portal ${RUN}`

  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await apiPost(page, '/api/v1/catalog/products', {
    name: name,
    price_cents: 350,
    prep_minutes: 8,
  })

  // The board is cleared: earlier runs' tickets pile up and past the screen's
  // cap the new one would not fit.
  await clearBoard(page)

  await signOut(page)

  // From here on, nobody has signed into anything.
  await openMenu(page, TENANTS.arepera)

  await expect(page.getByRole('heading', { name: 'Arepera El Sazón' })).toBeVisible()
  await expect(page.getByText('Abierto ahora')).toBeVisible()

  await addToCart(page, name, 2)

  await expect(cartBar(page)).toContainText('$7,00')
  await cartBar(page).click()

  // Checkout is a single scroll: no steps and no "next".
  await expect(page.getByRole('heading', { name: 'Tu pedido' })).toBeVisible()

  await page.getByRole('button', { name: 'Lo busco' }).click()
  await page.getByLabel('¿Cómo te llamas?').fill(`Cliente ${RUN}`)
  await page.getByLabel('Teléfono').fill('04141234567')
  await page.getByRole('button', { name: 'Efectivo al recibir' }).click()

  await page.getByRole('button', { name: /Hacer el pedido/ }).click()

  // It ends on the tracking screen, with the link in the address bar: that is
  // what lets them come back tomorrow to see what happened.
  await expect(page).toHaveURL(/\/p\/[A-Za-z0-9]+$/)
  await expect(page.getByText('Recibido, ya lo vemos')).toBeVisible()

  const number = /Pedido #(\d+)/.exec(await page.getByRole('heading', { level: 1 }).innerText())
  expect(number).not.toBeNull()

  // Not in the kitchen yet: the tenant has to confirm it first.
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await page.goto(`http://${TENANTS.arepera}.localhost:8010/dashboard/pedidos`)

  // By the customer's name, unique to this run: the number runs into the time
  // in the row's text, so `#131` would also match `#1310`.
  const row = page.getByRole('listitem').filter({ hasText: `Cliente ${RUN}` })
  await expect(row).toContainText(`#${number![1]}`)

  await row.getByRole('button', { name: 'Confirmar' }).click()
  await signOut(page)

  // And now: the ticket appeared by itself in the kitchen.
  await page.evaluate(() => localStorage.clear())
  await enterKitchen(page, TENANTS.arepera, 'Carlos', '4567')

  await expect(kitchenTicket(page, Number(number![1]))).toContainText(name)
})

test('delivery charges the zone\'s fee, and the customer sees it before ordering', async ({ page }) => {
  const name = `[e2e] Arepa reparto ${RUN}`

  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await apiPost(page, '/api/v1/catalog/products', { name: name, price_cents: 500 })
  await signOut(page)

  await openMenu(page, TENANTS.arepera)
  await addToCart(page, name)
  await cartBar(page).click()

  await page.getByRole('button', { name: 'Me lo traen' }).click()

  // The fee and the minutes are IN the option: chosen knowing the cost and the
  // wait, not afterwards.
  const zone = page.getByLabel('¿A qué zona?')
  const palosGrandes = await zone
    .locator('option')
    .filter({ hasText: 'Los Palos Grandes' })
    .getAttribute('value')

  await zone.selectOption(palosGrandes ?? '')

  // The fee and the minutes are IN the option: chosen knowing the cost.
  await expect(zone).toContainText('$2,00')

  await page.getByLabel('Dirección').fill(`Cuarta avenida, casa ${RUN}`)
  await page.getByLabel('¿Cómo te llamas?').fill(`Cliente ${RUN}`)
  await page.getByLabel('Teléfono').fill('04141234567')

  // 5.00 for the product + 2.00 delivery. The button states the total.
  await expect(page.getByRole('button', { name: /Hacer el pedido/ })).toContainText('$7,00')
})

test('the button says WHAT IS MISSING, not just that it cannot be done', async ({ page }) => {
  const name = `[e2e] Arepa falta ${RUN}`

  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await apiPost(page, '/api/v1/catalog/products', { name: name, price_cents: 300 })
  await signOut(page)

  await openMenu(page, TENANTS.arepera)
  await addToCart(page, name)
  await cartBar(page).click()

  // A grey button with no explanation leaves somebody staring at the screen
  // with no idea what to tap.
  await expect(page.getByRole('button', { name: 'Falta tu nombre' })).toBeDisabled()

  await page.getByLabel('¿Cómo te llamas?').fill(`Cliente ${RUN}`)
  await expect(page.getByRole('button', { name: 'Falta tu teléfono' })).toBeDisabled()

  await page.getByLabel('Teléfono').fill('04141234567')
  await expect(page.getByRole('button', { name: /Hacer el pedido/ })).toBeEnabled()
})

test('the basket survives the customer closing and coming back', async ({ page }) => {
  // A call comes in while they browse and they come back ten minutes later.
  // Finding an empty basket means starting over.
  const name = `[e2e] Arepa carrito ${RUN}`

  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await apiPost(page, '/api/v1/catalog/products', { name: name, price_cents: 300 })
  await signOut(page)

  await openMenu(page, TENANTS.arepera)
  await addToCart(page, name, 3)
  await expect(cartBar(page)).toContainText('$9,00')

  await page.reload()

  await expect(cartBar(page)).toContainText('$9,00')
})

test('mobile payment asks for the receipt, says who to pay and how long is left', async ({ page }) => {
  const name = `[e2e] Arepa transferida ${RUN}`

  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await apiPost(page, '/api/v1/catalog/products', { name: name, price_cents: 400 })
  await signOut(page)

  await openMenu(page, TENANTS.arepera)
  await addToCart(page, name)
  await cartBar(page).click()

  await page.getByRole('button', { name: 'Lo busco' }).click()
  await page.getByLabel('¿Cómo te llamas?').fill(`Cliente ${RUN}`)
  await page.getByLabel('Teléfono').fill('04141234567')
  await page.getByRole('button', { name: 'Pago móvil', exact: true }).click()

  // Where the money goes, BEFORE ordering. A pay button that does not say who
  // to pay is a guaranteed phone call.
  await expect(page.getByText(/Banco de Venezuela/)).toBeVisible()

  await page.getByRole('button', { name: /Hacer el pedido/ }).click()

  await expect(page.getByText('Esperando tu comprobante')).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Manda tu comprobante' })).toBeVisible()

  /*
   * AND HOW LONG THEY HAVE LEFT.
   *
   * What was missing and cost the most: an order with no receipt cancels
   * itself, and the customer saw no clock. The order died in their hand with no
   * warning, and from outside it looked like the system had eaten it.
   *
   * The minutes are counted by the server, so the assertion is on the sentence
   * rather than a specific number: the tenant's setting can change.
   */
  await expect(page.getByText(/Te queda(n)? .* para mandar el comprobante/)).toBeVisible()
})

test('the order link opens for anyone who has it, and only that order', async ({ page }) => {
  const name = `[e2e] Arepa enlace ${RUN}`

  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await apiPost(page, '/api/v1/catalog/products', { name: name, price_cents: 300 })
  await signOut(page)

  await openMenu(page, TENANTS.arepera)
  await addToCart(page, name)
  await cartBar(page).click()

  await page.getByRole('button', { name: 'Lo busco' }).click()
  await page.getByLabel('¿Cómo te llamas?').fill(`Cliente ${RUN}`)
  await page.getByLabel('Teléfono').fill('04141234567')
  await page.getByRole('button', { name: /Hacer el pedido/ }).click()

  await expect(page).toHaveURL(/\/p\//)
  const token = page.url().split('/p/')[1] ?? ''

  // EVERYTHING in the browser is cleared: the link has to stand alone, which is
  // exactly what is needed when the customer went off to the banking app.
  await page.context().clearCookies()
  await page.evaluate(() => localStorage.clear())

  await page.goto(trackAddress(TENANTS.arepera, token))
  await expect(page.getByText('Recibido, ya lo vemos')).toBeVisible()

  // And an invented token opens nothing: 404, never another order's screen.
  await page.goto(trackAddress(TENANTS.arepera, 'noExisteEsteTokenLargo1'))
  await expect(page.getByRole('heading', { name: 'No encontramos ese pedido' })).toBeVisible()
})
