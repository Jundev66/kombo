import { expect, type Page } from '@playwright/test'
import { posOf, PASSWORD } from './addresses'
import { apiPost } from './api'

/**
 * Entering the till, through both of its doors.
 *
 * The same ones as the kitchen and for the same reason: it is a shared shop
 * machine. Registration happens once in the device's life with email and
 * password; after that you enter with a four-digit PIN.
 */
export async function enterRegister(
  page: Page,
  tenant: string,
  cashier: string,
  pin: string,
  signupEmail = 'maria@elsazon.test',
  signupPassword = PASSWORD,
): Promise<void> {
  await page.goto(posOf(tenant))

  const signup = page.getByRole('button', { name: 'Dar de alta' })
  const person = page.getByRole('heading', { name: '¿Quién está en la caja?' })

  // The till paints a spinner while `/me` answers. Probing the door before it
  // is drawn reads "not registered" as "already registered" and leaves the
  // test standing at the wrong one.
  await expect(signup.or(person)).toBeVisible()

  if (await signup.isVisible()) {
    await page.getByLabel('Correo').fill(signupEmail)
    await page.getByLabel('Contraseña').fill(signupPassword)
    await signup.click()
  }

  await expect(page.getByRole('heading', { name: '¿Quién está en la caja?' })).toBeVisible()
  await page.getByRole('button', { name: cashier }).click()

  // Submitted on the fourth digit. An extra confirm button is an extra tap
  // with a customer waiting.
  for (const digit of pin) {
    await page.getByRole('button', { name: digit, exact: true }).click()
  }
}

/**
 * A product on the menu, seeded through the API with the dashboard session.
 *
 * Seeded by API rather than through the catalog screen on purpose: what this
 * test checks is the till, and walking the product form here would let a
 * catalog bug break these tests too.
 */
export async function seedProduct(
  page: Page,
  name: string,
  priceCents: number,
  modifierGroupIds: string[] = [],
): Promise<string> {
  const { data } = await apiPost<{ data: { id: string } }>(page, '/api/v1/catalog/products', {
    name,
    price_cents: priceCents,
    prep_minutes: 5,
    modifier_group_ids: modifierGroupIds,
  })

  return data.id
}

/** The bill panel: what the customer is taking. */
export function saleTicket(page: Page) {
  return page.getByRole('complementary', { name: 'La cuenta' })
}

/** The note handed to the customer. */
export function note(page: Page) {
  return page.getByRole('dialog')
}
