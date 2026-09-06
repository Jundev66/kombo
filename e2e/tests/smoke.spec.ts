import { expect, test } from '@playwright/test'
import { adminAddress, posOf, kdsOf, dashboardOf, portalOf, TENANTS } from '../support/addresses'
import { apiStatus } from '../support/api'

/*
 * The scaffolding's smoke test.
 *
 * It checks no business rule: it checks that routing by subdomain and by path
 * works end to end — nginx, the five Vite servers and the API — and that each
 * address serves the app it should and not another.
 */

test('a tenant\'s subdomain serves its portal', async ({ page }) => {
  await page.goto(portalOf(TENANTS.arepera))

  // The portal introduces itself with the TENANT's name, not the platform's:
  // somebody arriving from a WhatsApp link has to recognise where they eat, not
  // learn what software they use.
  // By role and by visible text, never by class or data-testid: if a control
  // cannot be reached that way, the fix belongs in the component.
  await expect(page.getByRole('heading', { name: 'Arepera El Sazón' })).toBeVisible()
})

/*
 * The till and the kitchen are served behind their gate, so what has to be
 * checked here is that each address serves ITS OWN.
 *
 * The device's default name is checked rather than a heading: the gate's
 * heading is the TENANT's name — whoever switches the machine on has to know
 * they are in the right shop — and it is the same on both.
 */
const shopScreens = [
  { name: 'caja', url: posOf(TENANTS.arepera), aparato: 'Caja' },
  { name: 'pantalla de cocina', url: kdsOf(TENANTS.arepera), aparato: 'Cocina' },
  // The dashboard is not here: it already asks you to sign in, so its journey
  // lives in `login.spec.ts` with a real login.
]

for (const screen of shopScreens) {
  test(`el subdominio de un negocio sirve su ${screen.name}`, async ({ page }) => {
    await page.goto(screen.url)

    await expect(page.getByRole('heading', { name: 'Arepera El Sazón' })).toBeVisible()
    await expect(page.getByLabel('Nombre de la pantalla')).toHaveValue(screen.aparato)
  })
}

test('platform administration lives outside the tenants', async ({ page }) => {
  await page.goto(adminAddress())

  await expect(page.getByRole('heading', { name: /Administración/i })).toBeVisible()
})

test('another tenant comes in through its own subdomain, with no parameters', async ({ page }) => {
  // The subdomain IS the tenant. That this works without touching nginx, the
  // test resolver or a list anywhere is why signing a customer up costs one row
  // in `tenants`.
  await page.goto(portalOf(TENANTS.pizzeria))

  // And it serves ITS OWN: the same code, another tenant, with no parameter in
  // between.
  await expect(page.getByRole('heading', { name: 'Pizzería La Esquina' })).toBeVisible()
})

test('the API answers from the same origin as the portal', async ({ page }) => {
  // `/up` answering 200 from inside the page proves two things at once: that
  // nginx does not send that route to Vite, and that the API is alive behind the
  // same origin — which is what allows a session cookie without CORS.
  await page.goto(portalOf(TENANTS.arepera))

  expect(await apiStatus(page, '/up')).toBe(200)
})
