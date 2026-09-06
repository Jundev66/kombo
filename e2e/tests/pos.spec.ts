import { expect, test } from '@playwright/test'
import { TENANTS } from '../support/addresses'
import { apiFetch, apiPost } from '../support/api'
import { enterRegister, seedProduct, saleTicket } from '../support/pos'
import { clearBoard, kitchenTicket, enterKitchen } from '../support/kds'
import { signIn, signOut } from '../support/dashboard'

/*
 * THE COUNTER TILL.
 *
 * What gets used every day with a customer in front of you, so the tests walk
 * what a person does: tap products, choose add-ons, take mixed payment and hand
 * over the paper. And they check the one thing not visible from the till: that
 * the ticket landed in the kitchen by itself.
 */

const RUN = Date.now().toString(36).slice(-5).toUpperCase()

test('charging at the counter hands over the note and sends the ticket to the kitchen', async ({ page }) => {
  // The dashboard session is only used to seed the menu; the till comes in
  // afterwards through its own door, with a PIN.
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  const name = `[e2e] Arepa caja ${RUN}`
  await seedProduct(page, name, 300)

  // The kitchen board is cleared first: earlier runs' tickets pile up, and past
  // the screen's cap the new one would not fit.
  await clearBoard(page)

  await signOut(page)

  await enterRegister(page, TENANTS.arepera, 'Ana', '3456')

  await page.getByRole('button', { name: name }).click()
  await expect(saleTicket(page)).toContainText(name)

  await page.getByRole('button', { name: 'Cobrar', exact: true }).click()

  const charged = page.getByRole('dialog', { name: 'Cobrar' })
  await expect(charged).toBeVisible()
  await charged.getByRole('button', { name: 'Cobrar $3,00' }).click()

  // The paper says what it is. Both sentences come from the server, stored
  // inside the document itself.
  const note = page.getByRole('dialog')
  await expect(note).toContainText('NOTA DE ENTREGA')
  await expect(note).toContainText('No es una factura')
  await expect(note).toContainText('$3,00')

  // The sequence number is in the note's title.
  expect(await note.getAttribute('aria-label')).toMatch(/^Nota A-\d{6}$/)

  const order = /Pedido #(\d+)/.exec(await note.innerText())
  expect(order).not.toBeNull()

  // And what is not visible from the till: the ticket landed in the kitchen by
  // itself, with nobody telling anybody.
  // Browser storage is cleared before going: /pos/ and /kds/ are the same
  // origin, so without this the kitchen would inherit Ana's shift and its own
  // door would not really be walked.
  await page.evaluate(() => localStorage.clear())

  await enterKitchen(page, TENANTS.arepera, 'Carlos', '4567')
  await expect(kitchenTicket(page, Number(order![1]))).toContainText(name)
})

test('add-ons are chosen before charging, and they are charged', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')

  await apiPost(page, '/api/v1/catalog/modifier-groups', {
    name: `[e2e] Punto ${RUN}`,
    // Required: you cannot proceed without answering "how would you like the meat?".
    min_choices: 1,
    max_choices: 1,
    modifiers: [
      { name: `Término medio ${RUN}`, price_delta_cents: 0 },
      { name: `Con todo ${RUN}`, price_delta_cents: 150 },
    ],
  })

  const groups = await apiFetch<{ data: Array<{ id: string; name: string }> }>(
    page,
    '/api/v1/catalog/modifier-groups',
  )

  const group = groups.data.find((g) => g.name === `[e2e] Punto ${RUN}`)
  expect(group).toBeDefined()

  const name = `[e2e] Hamburguesa ${RUN}`
  await seedProduct(page, name, 500, [group!.id])

  await signOut(page)

  await enterRegister(page, TENANTS.arepera, 'Ana', '3456')

  await page.getByRole('button', { name: name }).click()

  // The sheet does not let you proceed until the required question is answered.
  const sheet = page.getByRole('dialog', { name: name })
  await expect(sheet.getByRole('button', { name: /Falta elegir/ })).toBeDisabled()

  await sheet.getByRole('radio', { name: `Con todo ${RUN}` }).check()

  // 5.00 + 1.50: the add-on is charged.
  await sheet.getByRole('button', { name: 'Agregar · $6,50' }).click()

  await expect(saleTicket(page)).toContainText(`Con todo ${RUN}`)
  await expect(saleTicket(page)).toContainText('$6,50')
})

test('payment comes in a mix: part cash and the rest by mobile transfer', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  const name = `[e2e] Combo mixto ${RUN}`
  await seedProduct(page, name, 1000)

  await signOut(page)

  await enterRegister(page, TENANTS.arepera, 'Ana', '3456')

  await page.getByRole('button', { name: name }).click()
  await page.getByRole('button', { name: 'Cobrar', exact: true }).click()

  const charged = page.getByRole('dialog', { name: 'Cobrar' })

  // Four dollars in cash…
  await charged.getByLabel('Monto').fill('4,00')
  await charged.getByRole('button', { name: 'Agregar este pago' }).click()

  // …and the screen says how much is left, with no mental arithmetic.
  await expect(charged).toContainText('Falta')
  await expect(charged).toContainText('$6,00')

  // …the rest by mobile transfer, with its reference.
  await charged.getByRole('button', { name: 'Pago móvil' }).click()
  await charged.getByLabel('Referencia').fill(`99${RUN}`)
  await charged.getByRole('button', { name: 'Agregar este pago' }).click()

  await charged.getByRole('button', { name: 'Cobrar $10,00' }).click()

  const note = page.getByRole('dialog')
  await expect(note).toContainText('Efectivo $')
  await expect(note).toContainText(`Pago móvil · 99${RUN}`)
  await expect(note).toContainText('$10,00')
})

test('a tenant with no till says so plainly rather than failing at payment', async ({ page }) => {
  // The pizzeria sells only through the portal. Its till does not exist, and
  // that is said before anybody builds a whole order.
  await enterRegister(page, TENANTS.pizzeria, 'Pedro', '1234', 'pedro@laesquina.test')

  await expect(page.getByRole('heading', { name: 'Este negocio no tiene caja' })).toBeVisible()
})

/*
 * THE OWNER SUPERVISES THEIR OWN TILL.
 *
 * This used to be impossible without identifying yourself twice: having just
 * signed into the dashboard with a password, the till asked for the device
 * registration and a PIN. Now the dashboard has the link and the session works,
 * with the screen stating in full whose name the sale carries.
 */
test('the owner enters the till from the dashboard, with no registration and no PIN', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')

  const name = `[e2e] Arepa supervisión ${RUN}`
  await seedProduct(page, name, 300)

  // No `signOut`: the whole point is that the dashboard session works here.
  await page.getByRole('button', { name: 'Más' }).first().click()
  await page.getByRole('link', { name: 'Caja', exact: true }).click()

  // Neither the device registration nor the PIN pad.
  await expect(page.getByRole('button', { name: 'Dar de alta' })).toBeHidden()
  await expect(page.getByRole('heading', { name: '¿Quién está en la caja?' })).toBeHidden()

  // And the screen says who is operating. Not decoration: what is sold here
  // carries that name.
  await expect(page.getByRole('status', { name: 'Supervisión' })).toContainText('Supervisando · María')

  // It really sells, which is what separates supervising from watching.
  await page.getByRole('button', { name: name }).click()
  await expect(saleTicket(page)).toContainText(name)

  await page.getByRole('button', { name: 'Cobrar', exact: true }).click()

  const charged = page.getByRole('dialog', { name: 'Cobrar' })
  await charged.getByRole('button', { name: 'Cobrar $3,00' }).click()

  await expect(page.getByRole('dialog')).toContainText('NOTA DE ENTREGA')
})

test('going back to the dashboard from the till does not close the session', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')

  await page.getByRole('button', { name: 'Más' }).first().click()
  await page.getByRole('link', { name: 'Caja', exact: true }).click()

  await expect(page.getByRole('status', { name: 'Supervisión' })).toContainText('Supervisando')

  // Deliberately not "Sign out": there is no shift to close, and whoever came
  // from the dashboard expects to go back to it.
  await page.getByRole('button', { name: 'Volver al panel' }).click()

  await expect(page.getByRole('button', { name: 'Salir' })).toBeVisible()
  expect(page.url()).toContain('/dashboard/')
})
