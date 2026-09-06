import { expect, type Page } from '@playwright/test'
import { kdsOf, PASSWORD } from './addresses'
import { apiFetch, apiPost } from './api'

/**
 * Entering the kitchen screen, through both of its doors.
 *
 * Both are walked for real with no shortcut: they are exactly the ones used on
 * the shop floor, and testing a shortcut would leave the only thing worth
 * testing uncovered.
 */
export async function enterKitchen(
  page: Page,
  tenant: string,
  cook: string,
  pin: string,
  signupEmail = 'maria@elsazon.test',
  signupPassword = PASSWORD,
): Promise<void> {
  await page.goto(kdsOf(tenant))

  const signup = page.getByRole('button', { name: 'Dar de alta' })
  const person = page.getByRole('heading', { name: '¿Quién está en la cocina?' })

  // First door: registering the tablet. Only once in its life, but every test
  // starts with a clean browser. Waited for before it is probed: reading the
  // door before it is drawn skips the registration.
  await expect(signup.or(person)).toBeVisible()

  if (await signup.isVisible()) {
    await page.getByLabel('Correo').fill(signupEmail)
    await page.getByLabel('Contraseña').fill(signupPassword)
    await signup.click()
  }

  // Second door: who is in the kitchen.
  await expect(person).toBeVisible()
  await page.getByRole('button', { name: cook }).click()

  // The PIN submits on the fourth digit: there is no confirm button, because an
  // extra tap with full hands is an extra tap.
  for (const digit of pin) {
    await page.getByRole('button', { name: digit, exact: true }).click()
  }

  await expect(page.getByRole('heading', { name: 'Cocina' })).toBeVisible()
}

/**
 * A specific ticket's card, by its NAME.
 *
 * Not by its text, which on this screen runs into the stopwatch — "#13165:00" —
 * so `#131` would also match `#1310`. The card's accessible name says exactly
 * which one it is.
 */
export function kitchenTicket(page: Page, number: number) {
  return page.getByRole('article', { name: `Comanda #${number}`, exact: true })
}

/**
 * Leaves the kitchen board empty.
 *
 * Seeding is additive and nobody serves earlier runs' tickets, so they pile up
 * forever. Past the screen's cap the new ones no longer fit — the screen says
 * so, that is what the notice is for — but the test looking for its own does
 * not find it and fails for an unrelated reason.
 *
 * Called with the DASHBOARD session set, which is the one with permission to
 * move tickets.
 */
export async function clearBoard(page: Page): Promise<void> {
  const { data } = await apiFetch<{ data: { id: string; status: string }[] }>(
    page,
    '/api/v1/kitchen/tickets',
  )

  for (const ticket of data) {
    // From wherever it is to served: the state machine only advances one step at
    // a time, on purpose.
    const steps = ['preparing', 'ready', 'served'].slice(
      ['pending', 'preparing', 'ready'].indexOf(ticket.status),
    )

    for (const step of steps) {
      await apiPost(page, `/api/v1/kitchen/tickets/${ticket.id}/advance`, { status: step })
    }
  }
}
