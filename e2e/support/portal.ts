import { expect, type Page } from '@playwright/test'
import { addressOf, portalOf } from './addresses'

/**
 * The portal, as somebody off the street uses it.
 *
 * No session, no account and nothing stored: every test starts with a clean
 * browser, which is exactly the situation of a customer opening the link for
 * the first time.
 */

/** Opens the menu and waits for it to really load. */
export async function openMenu(page: Page, tenant: string): Promise<void> {
  await page.goto(portalOf(tenant))

  // It waits for something of the TENANT's, not platform text: until
  // `/portal/shop` arrives there is no menu to tap.
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
}

/** An order's tracking screen, by its link. */
export function trackAddress(tenant: string, token: string): string {
  return addressOf(tenant, `/p/${token}`)
}

/**
 * Adds a product to the order, through its sheet.
 *
 * Every product opens the sheet — including those with no add-ons — because
 * that is where the quantity lives.
 */
export async function addToCart(page: Page, product: string, quantity = 1): Promise<void> {
  await page.getByRole('button', { name: product }).click()

  const sheet = page.getByRole('dialog', { name: product })
  await expect(sheet).toBeVisible()

  for (let i = 1; i < quantity; i++) {
    await sheet.getByRole('button', { name: 'Uno más' }).click()
  }

  await sheet.getByRole('button', { name: /Agregar/ }).click()
  await expect(sheet).toBeHidden()
}

/** The bottom bar, which says how much you are carrying. */
export function cartBar(page: Page) {
  return page.getByRole('link', { name: /Ver mi pedido/ })
}
