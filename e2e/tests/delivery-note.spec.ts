import { expect, test, type Page } from '@playwright/test'
import { TENANTS } from '../support/addresses'
import { enterRegister, seedProduct } from '../support/pos'
import { signIn, signOut } from '../support/dashboard'

/*
 * THE DELIVERY NOTE.
 *
 * A COMMERCIAL document, not a fiscal one, and the paper says so in full. It
 * does not replace an invoice or remove the tenant's tax obligations: what this
 * design does is not PRETEND to issue a fiscal document.
 *
 * And a sequence number that gets reused is good for nothing: if two pieces of
 * paper can carry the same number, the number identifies neither.
 */

const RUN = Date.now().toString(36).slice(-5).toUpperCase()

/** Charges a simple sale and returns the note number that came out. */
async function chargeOne(page: Page, product: string, importe: string): Promise<number> {
  await page.getByRole('button', { name: product }).click()
  await page.getByRole('button', { name: 'Cobrar', exact: true }).click()

  const charged = page.getByRole('dialog', { name: 'Cobrar' })
  await charged.getByRole('button', { name: `Cobrar ${importe}` }).click()

  const note = page.getByRole('dialog')
  await expect(note).toContainText('NOTA DE ENTREGA')

  const reference = /Nota A-(\d{6})/.exec((await note.getAttribute('aria-label')) ?? '')
  expect(reference).not.toBeNull()

  return Number(reference![1])
}

test('the document says it is NOT an invoice', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  const name = `[e2e] Arepa nota ${RUN}`
  await seedProduct(page, name, 300)

  await signOut(page)

  await enterRegister(page, TENANTS.arepera, 'Ana', '3456')
  await chargeOne(page, name, '$3,00')

  const note = page.getByRole('dialog')

  // The literal heading, with the notice right below. Neither is configurable:
  // they come stored inside the document itself.
  await expect(note).toContainText('NOTA DE ENTREGA')
  await expect(note).toContainText('No es una factura')

  // And what it does NOT have: anything suggesting fiscal backing.
  await expect(note).not.toContainText('FACTURA', { ignoreCase: false })
  await expect(note).not.toContainText('Número de control')
})

test('the sequence runs one after another', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  const name = `[e2e] Arepa serie ${RUN}`
  await seedProduct(page, name, 300)

  await signOut(page)

  await enterRegister(page, TENANTS.arepera, 'Ana', '3456')

  const first = await chargeOne(page, name, '$3,00')
  await page.getByRole('button', { name: 'Nueva venta' }).click()

  const second = await chargeOne(page, name, '$3,00')

  // The RELATIONSHIP is checked, not the number: the database is additive
  // between runs, and expecting "A-000001" would pass only the first time.
  expect(second).toBe(first + 1)
})

test('voiding leaves a record and does NOT release the number', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  const name = `[e2e] Arepa anulada ${RUN}`
  await seedProduct(page, name, 300)

  // Ana is on the counter: she can START the void, not carry it out. She will
  // be asked for José's PIN, and the void is recorded in his name.
  await signOut(page)

  await enterRegister(page, TENANTS.arepera, 'Ana', '3456')

  const voidedNote = await chargeOne(page, name, '$3,00')

  await page.getByRole('button', { name: 'Anular esta venta' }).click()
  await page.getByLabel('¿Por qué se anula?').fill(`Se equivocó de pedido ${RUN}`)

  await page.getByLabel('Autoriza').selectOption({ label: 'José · Encargado' })
  await page.getByLabel('PIN').fill('2345')
  await page.getByRole('button', { name: 'Anular', exact: true }).click()

  const note = page.getByRole('dialog')
  await expect(note).toContainText('ANULADA')
  await expect(note).toContainText(`Se equivocó de pedido ${RUN}`)

  // The voided number stays used: the next sale takes the next one.
  await page.getByRole('button', { name: 'Nueva venta' }).click()

  const next = await chargeOne(page, name, '$3,00')
  expect(next).toBe(voidedNote + 1)
})

test('without a manager\'s PIN, the counter voids nothing', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  const name = `[e2e] Arepa sin pin ${RUN}`
  await seedProduct(page, name, 300)

  await signOut(page)

  await enterRegister(page, TENANTS.arepera, 'Ana', '3456')

  const number = await chargeOne(page, name, '$3,00')

  await page.getByRole('button', { name: 'Anular esta venta' }).click()
  await page.getByLabel('¿Por qué se anula?').fill('Sin autorización')

  // The PIN is asked for BEFORE trying, because `/me` already said this action
  // needs it. With the field empty, nothing is voided.
  await page.getByRole('button', { name: 'Anular', exact: true }).click()

  const note = page.getByRole('dialog')
  await expect(note).not.toContainText('ANULADA')
  expect(await note.getAttribute('aria-label')).toBe(
    `Nota A-${String(number).padStart(6, '0')}`,
  )
})
