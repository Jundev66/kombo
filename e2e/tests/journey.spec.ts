import { expect, test, type Page } from '@playwright/test'
import { addressOf, adminAddress, dashboardOf } from '../support/addresses'
import { apiFetch } from '../support/api'
import { enterRegister, saleTicket } from '../support/pos'
import { kitchenTicket, enterKitchen } from '../support/kds'
import { signIn, signOut } from '../support/dashboard'
import { addToCart, cartBar, openMenu } from '../support/portal'

/*
 * FROM ZERO TO A TENANT AT WORK.
 *
 * Everything else in this directory tests one screen against already-seeded
 * tenants. This tests the other thing: a tenant that did not exist, signed up
 * from platform administration, filled in by hand by its owner, and taken all
 * the way to taking payment through both doors — portal and counter — with the
 * kitchen and delivery in between.
 *
 * It is the journey a new customer makes on day one, and the only one that
 * catches a whole class of failure: the ones that appear only when there is NO
 * prior data. An empty menu, a tenant with no zones, a one-person team, a note
 * sequence starting at zero.
 *
 * Serial, on purpose: each test is a step of that first day and leans on the
 * previous one. If sign-up fails, the eleven that follow have nothing to say.
 */

test.describe.configure({ mode: 'serial' })

const RUN = Date.now().toString(36).slice(-5).toLowerCase()

/** This run's tenant. Seeding is additive: each run creates its own. */
const TENANT = {
  slug: `cero-${RUN}`,
  name: `Arepera Cero ${RUN.toUpperCase()}`,
}

const OWNER = { name: 'Rosa', email: `rosa-${RUN}@cero.test`, password: 'clave-larga-123' }

/** The team assembled in step 2, used by the steps that follow. */
const TEAM = {
  caja: { name: `Ana ${RUN}`, email: `ana-${RUN}@cero.test`, role: 'Mostrador', pin: '3456' },
  cocina: { name: `Beto ${RUN}`, email: `beto-${RUN}@cero.test`, role: 'Cocina', pin: '4567' },
  delivery: { name: `Luis ${RUN}`, email: `luis-${RUN}@cero.test`, role: 'Repartidor', pin: '' },
}

const CATEGORY = `Arepas ${RUN}`
const ADDONS = `¿Con qué la quieres? ${RUN}`
const AREPA = `Reina pepiada ${RUN}`
const REFRESCO = `Refresco ${RUN}`
const ZONE = `Los Palos Grandes ${RUN}`

/** The portal order the kitchen and delivery are going to touch. */
let portalOrder = { number: 0, customer: '' }

async function loginAsOwner(page: Page): Promise<void> {
  await signIn(page, TENANT.slug, OWNER.email, OWNER.password)
}

/**
 * Switching person on the same screen.
 *
 * It returns to the dashboard ROOT before signing in, and that is not
 * decoration: on sign-out the address stays where it was. If it was `/team` and
 * the next person is the courier — who has no such screen — they land on "that
 * screen does not exist" instead of on their work.
 */
async function switchPerson(page: Page, email: string): Promise<void> {
  await signOut(page)
  await page.goto(dashboardOf(TENANT.slug))

  await page.getByLabel('Correo').fill(email)
  await page.getByLabel('Contraseña').fill(OWNER.password)
  await page.getByRole('button', { name: 'Entrar' }).click()
}

/** A dashboard screen, by its route. */
async function goTo(page: Page, path: string): Promise<void> {
  await page.goto(dashboardOf(TENANT.slug) + path)
}

// ─────────────────────────────────────────────────────────────────────────────

test('1 · the tenant is signed up from platform administration', async ({ page }) => {
  await page.goto(adminAddress())
  await page.getByLabel('Correo').fill('admin@kombo.test')
  await page.getByLabel('Contraseña').fill('demo1234')
  await page.getByRole('button', { name: 'Entrar' }).click()
  await expect(page.getByRole('button', { name: 'Salir' })).toBeVisible()

  await page.getByRole('button', { name: 'Dar de alta' }).first().click()

  await page.getByRole('textbox', { name: 'Nombre', exact: true }).fill(TENANT.name)
  await page.getByLabel('Dirección').fill(TENANT.slug)
  // The full plan: the one that brings the till, notes, delivery and reports —
  // everything this journey is going to touch.
  await page.getByLabel('Plan').selectOption('complete')
  await page.getByLabel('Nombre del dueño').fill(OWNER.name)
  await page.getByLabel('Correo del dueño').fill(OWNER.email)
  await page.getByLabel('Contraseña').fill(OWNER.password)

  await page.getByRole('button', { name: 'Dar de alta', exact: true }).last().click()

  await expect(page.getByText(TENANT.slug)).toBeVisible()

  // And the only thing that proves sign-up worked: the owner signs in.
  await loginAsOwner(page)

  // Her portal is already up, with her name and still no menu.
  await page.goto(addressOf(TENANT.slug, '/'))
  await expect(page.getByRole('heading', { name: TENANT.name })).toBeVisible()
})

test('2 · the owner assembles her team: counter, kitchen and delivery', async ({ page }) => {
  await loginAsOwner(page)
  await goTo(page, 'equipo')

  await expect(page.getByRole('heading', { name: 'Equipo' })).toBeVisible()

  // It starts alone: sign-up creates the owner and nobody else.
  await expect(page.getByRole('listitem').filter({ hasText: OWNER.email })).toBeVisible()

  for (const person of Object.values(TEAM)) {
    await page.getByRole('button', { name: 'Sumar a alguien' }).click()

    await page.getByRole('textbox', { name: 'Nombre', exact: true }).fill(person.name)
    await page.getByLabel('Correo').fill(person.email)
    await page.getByLabel('Rol').selectOption({ label: person.role })
    await page.getByRole('textbox', { name: 'Contraseña', exact: true }).fill(OWNER.password)

    if (person.pin !== '') {
      await page.getByLabel('PIN').fill(person.pin)
    }

    await page.getByRole('button', { name: 'Guardar' }).click()

    const record = page.getByRole('listitem').filter({ hasText: person.name })
    await expect(record).toContainText(person.role)

    // The PIN is visible at a glance, and it is needed: without it there is no
    // till and no kitchen, and finding out with a customer waiting is too late.
    if (person.pin !== '') {
      await expect(record).toContainText('Con PIN')
    }
  }

  // And the courier really signs in, with their own role: they land on
  // Deliveries and see nothing else. That is what separates "the user was
  await switchPerson(page, TEAM.delivery.email)

  await expect(page.getByRole('heading', { name: 'Entregas' })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Carta' })).toBeHidden()
  await expect(page.getByRole('link', { name: 'Equipo' })).toBeHidden()
})

test('3 · she sets the opening hours, and the portal stops being closed', async ({ page }) => {
  await loginAsOwner(page)
  await goTo(page, 'horario')

  await expect(page.getByRole('heading', { name: 'Horario' })).toBeVisible()

  /*
   * 00:00 to 23:59 every day.
   *
   * Not a whim: sign-up leaves 08:00–20:00, so a suite run at eleven at night
   * would find the tenant closed and fail on the clock rather than the code. A
   * test that only passes in daylight is an intermittent test with a calendar.
   */
  for (const opensAt of await page.getByLabel(/^Abre el /).all()) {
    await opensAt.fill('00:00')
  }

  for (const closesAt of await page.getByLabel(/^Cierra el /).all()) {
    await closesAt.fill('23:59')
  }

  await page.getByRole('button', { name: 'Guardar el horario' }).click()
  await expect(page.getByText('Guardado')).toBeVisible()

  // The portal says so from the outside, which is where it matters.
  await signOut(page)
  await openMenu(page, TENANT.slug)
  await expect(page.getByText('Abierto ahora')).toBeVisible()
})

test('4 · she loads the rate of the day', async ({ page }) => {
  await loginAsOwner(page)

  // The menu warns before it becomes a problem: with no rate there is no
  // charging in bolívares, and that gets discovered with a customer waiting.
  await goTo(page, 'carta')
  await expect(page.getByRole('alert')).toContainText('tasa del día')

  await goTo(page, 'tasa')
  await page.getByLabel('Bolívares por dólar').fill('40,00')
  await page.getByRole('button', { name: 'Guardar la tasa' }).click()

  await expect(page.getByText('Bs 40 por dólar')).toBeVisible()

  // And with a real amount: $100 at 40 is Bs 4.000,00. A bare "40" does not
  // reveal an extra zero; this does.
  await expect(page.getByText('Bs 4.000,00')).toBeVisible()

  // The menu's warning is gone.
  await goTo(page, 'carta')
  await expect(page.getByRole('alert')).toBeHidden()
})

test('5 · she fills in the menu: category, add-ons and two products', async ({ page }) => {
  await loginAsOwner(page)

  // ── The category ──
  await goTo(page, 'categorias')
  await page.getByLabel('Nueva categoría').fill(CATEGORY)
  await page.getByRole('button', { name: 'Añadir' }).click()
  await expect(page.getByRole('listitem').filter({ hasText: CATEGORY })).toBeVisible()

  // ── The add-ons ──
  await goTo(page, 'agregados')
  await page.getByLabel('La pregunta').fill(ADDONS)
  await page.getByLabel('Opción 1', { exact: true }).fill('Sin cebolla')
  await page.getByRole('button', { name: 'Otra opción' }).click()
  await page.getByLabel('Opción 2', { exact: true }).fill('Extra queso')
  await page.getByLabel('Precio de la opción 2').fill('0,50')
  await page.getByRole('button', { name: 'Guardar el grupo' }).click()

  const group = page.getByRole('listitem').filter({ hasText: ADDONS })
  await expect(group.getByText('Extra queso $0,50')).toBeVisible()

  // ── An empty menu says what to do, not just that it is empty ──
  await goTo(page, 'carta')
  await expect(page.getByRole('link', { name: /Añadir el primero/i })).toBeVisible()

  // ── The first product, with its category and add-ons ──
  await goTo(page, 'carta/nuevo')
  await page.getByLabel('Nombre').fill(AREPA)
  await page.getByLabel('Precio en dólares').fill('3,50')
  await page.getByLabel('Categoría').selectOption({ label: CATEGORY })
  await page.getByLabel('Descripción').fill('Pollo y aguacate.')
  await page.getByLabel('Minutos que tarda').fill('8')
  // `getByLabel` and not `getByRole(..., {name})`: the checkbox's accessible
  // name is "{group} — {rule}", so an exact name does not fit. Nor does a
  // regular expression: the group name contains a "?", which in a regex is a
  // quantifier rather than a question mark.
  await page.getByLabel(ADDONS).check()
  await page.getByRole('button', { name: 'Guardar' }).click()

  // ── The second, so the order carries two lines ──
  await goTo(page, 'carta/nuevo')
  await page.getByLabel('Nombre').fill(REFRESCO)
  await page.getByLabel('Precio en dólares').fill('1,00')
  await page.getByRole('button', { name: 'Guardar' }).click()

  await page.getByRole('searchbox', { name: 'Buscar en la carta' }).fill(AREPA)
  const onMenu = page.getByRole('listitem').filter({ hasText: AREPA })
  await expect(onMenu).toContainText('$3,50')

  /*
   * And the price stored IN CENTS, not floating point.
   *
   * The screen saying "$3,50" proves nothing: 3.5 in floating point ends in a
   * cash count that does not balance three months later, and by then nobody
   * knows where it came from.
   */
  const { data } = await apiFetch<{ data: Array<{ name: string; priceCents: number }> }>(
    page,
    '/api/v1/catalog/products',
  )

  expect(data.find((p) => p.name === AREPA)?.priceCents).toBe(350)
})

test('6 · she adds the product photo, which is what sells on the portal', async ({ page }) => {
  await loginAsOwner(page)
  await goTo(page, 'carta')

  // Opened for editing: the photo hangs off a product that already exists.
  await page.getByRole('link', { name: new RegExp(AREPA) }).click()

  await expect(page.getByLabel('Nombre')).toHaveValue(AREPA)

  await page.getByLabel('Foto').setInputFiles({
    name: 'arepa.png',
    mimeType: 'image/png',
    // A one-pixel PNG. The server's `image` rule validates the CONTENT, not the
    // extension, so it has to be a real image.
    buffer: Buffer.from(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
      'base64',
    ),
  })

  // The remove button appears: that can only happen if the upload finished.
  await expect(page.getByRole('button', { name: 'Quitar' })).toBeVisible()

  const { data } = await apiFetch<{ data: Array<{ name: string; photoUrl: string | null }> }>(
    page,
    '/api/v1/catalog/products',
  )

  expect(data.find((p) => p.name === AREPA)?.photoUrl).toContain('/storage/products/')
})

test('7 · she maps out the zones she delivers to', async ({ page }) => {
  await loginAsOwner(page)
  await goTo(page, 'zonas')

  await expect(page.getByText('Todavía no repartes a ningún sitio')).toBeVisible()

  await page.getByLabel('Zona').fill(ZONE)
  await page.getByLabel('Se cobra').fill('2,00')
  await page.getByLabel('Minutos').fill('30')
  await page.getByRole('button', { name: 'Añadir' }).click()

  const zone = page.getByRole('listitem').filter({ hasText: ZONE })
  await expect(zone).toContainText('30 min')
  await expect(zone).toContainText('$2,00')
})

test('8 · a customer orders delivery from the portal, with no account', async ({ page }) => {
  // Nobody is signed in here: this is somebody off the street opening the link.
  await openMenu(page, TENANT.slug)

  await expect(page.getByRole('heading', { name: TENANT.name })).toBeVisible()
  await expect(page.getByText(CATEGORY)).toBeVisible()

  // The arepa with its paid add-on, and a soft drink.
  await page.getByRole('button', { name: new RegExp(AREPA) }).click()
  const sheet = page.getByRole('dialog', { name: new RegExp(AREPA) })
  await sheet.getByLabel('Extra queso').check()
  await sheet.getByRole('button', { name: /Agregar/ }).click()
  await expect(sheet).toBeHidden()

  await addToCart(page, REFRESCO)

  // 3.50 + 0.50 for the cheese + 1.00 for the drink.
  await expect(cartBar(page)).toContainText('$5,00')
  await cartBar(page).click()

  await expect(page.getByRole('heading', { name: 'Tu pedido' })).toBeVisible()

  await page.getByRole('button', { name: 'Me lo traen' }).click()

  const zone = page.getByLabel('¿A qué zona?')
  const value = await zone.locator('option').filter({ hasText: ZONE }).getAttribute('value')
  await zone.selectOption(value ?? '')

  // The fee is IN the option: it is chosen knowing the cost and the wait.
  await expect(zone).toContainText('$2,00')

  portalOrder.customer = `Cliente ${RUN.toUpperCase()}`

  await page.getByLabel('Dirección').fill(`Cuarta avenida, casa ${RUN}`)
  await page.getByLabel('¿Cómo te llamas?').fill(portalOrder.customer)
  await page.getByLabel('Teléfono').fill(`0414${Date.now().toString().slice(-7)}`)
  await page.getByRole('button', { name: 'Efectivo al recibir' }).click()

  // 5.00 for the order + 2.00 delivery. The button states the total.
  const placeOrder = page.getByRole('button', { name: /Hacer el pedido/ })
  await expect(placeOrder).toContainText('$7,00')
  await placeOrder.click()

  // It ends on the tracking screen, with the link in the address bar: that is
  // what lets them come back tomorrow to see what happened.
  await expect(page).toHaveURL(/\/p\/[A-Za-z0-9]+$/)
  await expect(page.getByText('Recibido, ya lo vemos')).toBeVisible()

  const header = await page.getByRole('heading', { level: 1 }).innerText()
  const number = /Pedido #(\d+)/.exec(header)

  expect(number).not.toBeNull()
  portalOrder.number = Number(number![1])

  // A new tenant's first order is number 1. The check that the sequence starts
  // per tenant rather than shared with anybody.
  expect(portalOrder.number).toBe(1)
})

test('9 · the owner confirms it and the ticket lands in the kitchen by itself', async ({ page }) => {
  await loginAsOwner(page)
  await goTo(page, 'pedidos')

  // By the customer's name: the number runs into the time in the row's text, so
  // "#1" would also match "#12".
  const row = page.getByRole('listitem').filter({ hasText: portalOrder.customer })

  await expect(row).toContainText(`#${portalOrder.number}`)
  await expect(row).toContainText(AREPA)

  await row.getByRole('button', { name: 'Confirmar' }).click()

  // And now the kitchen, through its own door: the tablet is registered with
  // the owner's password and then entered with the cook's PIN.
  await signOut(page)
  await page.evaluate(() => localStorage.clear())

  await enterKitchen(
    page,
    TENANT.slug,
    TEAM.cocina.name,
    TEAM.cocina.pin,
    OWNER.email,
    OWNER.password,
  )

  const card = kitchenTicket(page, portalOrder.number)

  await expect(card).toBeVisible()
  await expect(card).toContainText(AREPA)
  // The add-on, on its own line and in amber: exactly what gets skipped when
  // reading fast, and skipping it means remaking the dish.
  await expect(card).toContainText('Extra queso')
  await expect(card).toContainText('Delivery')

  // The cook starts it and finishes it.
  await card.getByRole('button', { name: 'Empezar' }).click()
  await expect(card.getByRole('button', { name: 'Listo' })).toBeVisible()

  await card.getByRole('button', { name: 'Listo' }).click()
  await expect(card.getByRole('button', { name: 'Entregado' })).toBeVisible()
})

test('10 · the courier takes it, goes out and delivers it', async ({ page }) => {
  /*
   * The ORDER is moved by the dashboard, not by the kitchen.
   *
   * The ticket and the order are two things: the kitchen screen carries the
   * food, the orders board carries the order. Marking "Ready" in the kitchen
   * does NOT put the order in "ready", so whoever is serving has to move it —
   * and until they do, it does not appear on the courier's screen.
   */
  await loginAsOwner(page)
  await goTo(page, 'pedidos')

  const row = page.getByRole('listitem').filter({ hasText: portalOrder.customer })

  await row.getByRole('button', { name: 'A la cocina' }).click()
  await row.getByRole('button', { name: 'Listo' }).click()

  // And now the courier, with their own account and role.
  await switchPerson(page, TEAM.delivery.email)

  await expect(page.getByRole('heading', { name: 'Entregas' })).toBeVisible()

  const card = page.getByText(`Cuarta avenida, casa ${RUN}`).locator('../..')

  // What to collect on arrival: 5.00 for the order plus 2.00 delivery. The only
  // thing the courier needs to know about the money.
  await expect(card).toContainText('Cobrar')
  await expect(card).toContainText('$7,00')

  await card.getByRole('button', { name: 'Lo llevo yo' }).click()
  await page.getByRole('button', { name: 'Salgo con él' }).click()
  await page.getByRole('button', { name: 'Entregado' }).click()

  // It leaves the list: no longer anybody's business.
  await expect(page.getByText(`Cuarta avenida, casa ${RUN}`)).toBeHidden()
})

test('11 · a counter sale comes out with its delivery note', async ({ page }) => {
  await enterRegister(
    page,
    TENANT.slug,
    TEAM.caja.name,
    TEAM.caja.pin,
    OWNER.email,
    OWNER.password,
  )

  // The arepa's question is optional, so the sheet allows adding without
  // answering. It is answered anyway, which is what somebody at the counter
  // does.
  await page.getByRole('button', { name: new RegExp(AREPA) }).click()

  const sheet = page.getByRole('dialog', { name: new RegExp(AREPA) })
  await sheet.getByLabel('Sin cebolla').check()
  await sheet.getByRole('button', { name: /Agregar/ }).click()

  // The drink asks nothing, so it joins the bill in one tap: at the counter, a
  // sheet that only says "add" is an extra tap with a customer waiting.
  await page.getByRole('button', { name: new RegExp(REFRESCO) }).click()

  await expect(saleTicket(page)).toContainText(AREPA)
  await expect(saleTicket(page)).toContainText(REFRESCO)
  await expect(saleTicket(page)).toContainText('$4,50')

  await page.getByRole('button', { name: 'Cobrar', exact: true }).click()

  const charged = page.getByRole('dialog', { name: 'Cobrar' })

  // Mixed, which is how payment really works: two dollars in cash…
  await charged.getByLabel('Monto').fill('2,00')
  await charged.getByRole('button', { name: 'Agregar este pago' }).click()

  // …and the screen says how much is left, with no mental arithmetic.
  await expect(charged).toContainText('$2,50')

  // …the rest by mobile transfer, with its reference.
  await charged.getByRole('button', { name: 'Pago móvil' }).click()
  await charged.getByLabel('Referencia').fill(`99${RUN}`)
  await charged.getByRole('button', { name: 'Agregar este pago' }).click()

  await charged.getByRole('button', { name: 'Cobrar $4,50' }).click()

  const note = page.getByRole('dialog')

  // The paper says what it is, and both sentences come from the server: they
  // are stored inside the document, not put there by the screen.
  await expect(note).toContainText('NOTA DE ENTREGA')
  await expect(note).toContainText('No es una factura')
  await expect(note).not.toContainText('FACTURA', { ignoreCase: false })

  await expect(note).toContainText(`Pago móvil · 99${RUN}`)
  await expect(note).toContainText('$4,50')

  // A new tenant's sequence starts at its first note. That is what proves the
  // series is PER TENANT rather than one shared.
  expect(await note.getAttribute('aria-label')).toBe('Nota A-000001')
})

test('12 · the customer book filled itself in', async ({ page }) => {
  await loginAsOwner(page)
  await goTo(page, 'clientes')

  // Nobody wrote it: the record came from the portal order.
  const record = page.getByRole('listitem').filter({ hasText: portalOrder.customer })

  await expect(record).toContainText('1 pedido')

  // The note is the only thing written by hand, and what makes the book worth
  // having.
  await record.getByRole('button', { name: portalOrder.customer }).click()
  await page.getByLabel('Nota').fill('Toca el timbre dos veces')
  await page.getByRole('button', { name: 'Guardar la nota' }).click()

  await page.reload()
  await page.getByRole('button', { name: portalOrder.customer }).click()
  await expect(page.getByLabel('Nota')).toHaveValue('Toca el timbre dos veces')
})

test('13 · the day\'s sales match what was charged', async ({ page }) => {
  await loginAsOwner(page)
  await goTo(page, 'ventas')

  await expect(page.getByRole('heading', { name: 'Ventas' })).toBeVisible()

  /*
   * Two sales and $11.50.
   *
   * 7.00 from the portal order — 5.00 of food plus 2.00 delivery — and 4.50
   * from the counter. The SUM is asserted rather than "there are sales": a
   * report that counts two orders but adds them up wrong is exactly the one
   * nobody finds until the owner reconciles the till by hand.
   */
  await expect(page.getByText('2 pedidos')).toBeVisible()
  await expect(page.getByText('$11,50')).toBeVisible()

  // And it can be taken away to a spreadsheet.
  const download = page.waitForEvent('download')
  await page.getByRole('link', { name: 'Exportar' }).click()

  expect((await download).suggestedFilename()).toContain(`pedidos-${TENANT.slug}`)
})
