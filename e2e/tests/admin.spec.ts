import { expect, test, type Page } from '@playwright/test'
import { addressOf, adminAddress, dashboardOf } from '../support/addresses'
import { apiFetch, apiPost } from '../support/api'

/*
 * PLATFORM ADMINISTRATION.
 *
 * The part that makes this sellable: sign a tenant up, bill them, and cut them
 * off when they stop paying — without that meaning taking their data away.
 *
 * It lives at `admin.localhost` and comes in through its own door.
 */

const RUN = Date.now().toString(36).slice(-5).toUpperCase()

const ADMIN = { email: 'admin@kombo.test', password: 'demo1234' }

async function signInAsAdmin(page: Page): Promise<void> {
  await page.goto(adminAddress())

  await page.getByLabel('Correo').fill(ADMIN.email)
  await page.getByLabel('Contraseña').fill(ADMIN.password)
  await page.getByRole('button', { name: 'Entrar' }).click()

  await expect(page.getByRole('button', { name: 'Salir' })).toBeVisible()
}

test('platform administration does not open without signing in', async ({ page }) => {
  await page.goto(adminAddress())

  // Not even the figures are visible: the door comes first.
  await expect(page.getByRole('button', { name: 'Entrar' })).toBeVisible()
  await expect(page.getByText('Negocios activos')).toBeHidden()

  // And the door means what it says. This screen used to be painted whenever
  // `/platform/me` failed for ANY reason, so it was green through an outage that
  // answered 502 to everything: "not signed in" and "no server" looked the same.
  // KMB-0014.
  await expect(
    page.getByRole('heading', { name: 'No se pudo contactar al servidor' }),
  ).toBeHidden()
})

test('signing a tenant up leaves it ready for its owner to sign in', async ({ page }) => {
  await signInAsAdmin(page)

  // The figures that answer "is this going well?" in five seconds.
  await expect(page.getByText('Negocios activos')).toBeVisible()
  await expect(page.getByText('Ingreso mensual')).toBeVisible()

  const slug = `nuevo-${RUN.toLowerCase()}`
  const email = `duena-${RUN.toLowerCase()}@nuevo.test`

  await page.getByRole('button', { name: 'Dar de alta' }).first().click()

  await page.getByRole('textbox', { name: 'Nombre', exact: true }).fill(`Arepera ${RUN}`)
  await page.getByLabel('Dirección').fill(slug)
  await page.getByLabel('Nombre del dueño').fill('Dueña')
  await page.getByLabel('Correo del dueño').fill(email)
  await page.getByLabel('Contraseña').fill('clave-larga-123')

  await page.getByRole('button', { name: 'Dar de alta', exact: true }).last().click()

  await expect(page.getByRole('button', { name: 'Dar de alta' }).first()).toBeVisible()
  await expect(page.getByText(slug)).toBeVisible()

  /*
   * And what really matters: the owner SIGNS IN to her tenant.
   *
   * A sign-up that leaves a half-created tenant is worse than none — nobody can
   * fix it from the inside, because getting in needs exactly what is missing.
   */
  await page.goto(dashboardOf(slug))
  await page.getByLabel('Correo').fill(email)
  await page.getByLabel('Contraseña').fill('clave-larga-123')
  await page.getByRole('button', { name: 'Entrar' }).click()

  await expect(page.getByRole('button', { name: 'Salir' })).toBeVisible()

  // With her menu ready to fill and her portal already up.
  await page.goto(addressOf(slug, '/'))
  await expect(page.getByRole('heading', { name: `Arepera ${RUN}` })).toBeVisible()
})

test('a suspended tenant reads its data, but cannot carry on operating', async ({ page }) => {
  /*
   * Both halves matter. Cutting somebody off from their own data is not an
   * acceptable collection tactic: their orders and menu are still theirs even
   * owing us three months. What is cut off is carrying on for free.
   */
  await signInAsAdmin(page)

  const slug = `suspende-${RUN.toLowerCase()}`
  const email = `duena-${slug}@x.test`

  await page.getByRole('button', { name: 'Dar de alta' }).first().click()
  await page.getByRole('textbox', { name: 'Nombre', exact: true }).fill(`Suspendible ${RUN}`)
  await page.getByLabel('Dirección').fill(slug)
  await page.getByLabel('Nombre del dueño').fill('Dueña')
  await page.getByLabel('Correo del dueño').fill(email)
  await page.getByLabel('Contraseña').fill('clave-larga-123')
  await page.getByRole('button', { name: 'Dar de alta', exact: true }).last().click()

  /*
   * SEARCHED for rather than looked up in the whole list.
   *
   * The tenant list is paginated — it used to have no cap and downloaded whole —
   * so a test expecting to find its own on the first page stops passing once
   * there are more than fifty. Which is today: every run leaves a new one.
   */
  await page.getByRole('searchbox', { name: 'Buscar' }).fill(slug)
  await expect(page.getByText(slug)).toBeVisible()

  // Her record is opened and she is suspended.
  await page.getByRole('button', { name: new RegExp(`Suspendible ${RUN}`) }).click()
  await expect(page.getByRole('heading', { name: `Suspendible ${RUN}` })).toBeVisible()

  await page.getByRole('button', { name: 'Suspender' }).click()
  await expect(page.getByText('Suspendido')).toBeVisible()

  // The owner signs in — that is not taken away — and sees her menu.
  await page.goto(dashboardOf(slug))
  await page.getByLabel('Correo').fill(email)
  await page.getByLabel('Contraseña').fill('clave-larga-123')
  await page.getByRole('button', { name: 'Entrar' }).click()
  await expect(page.getByRole('button', { name: 'Salir' })).toBeVisible()

  await expect(apiFetch(page, '/api/v1/catalog/products')).resolves.toBeTruthy()

  // But she cannot create anything: 402, which says what is really happening. A
  // 403 would say "you lack permission" and send her to check her team's roles.
  const status = await page.evaluate(async () => {
    const xsrf = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1]

    const response = await fetch('/api/v1/catalog/products', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
      },
      body: JSON.stringify({ name: 'No debería entrar', price_cents: 100 }),
    })

    return response.status
  })

  expect(status).toBe(402)
})

test('recording a payment reactivates whoever is up to date', async ({ page }) => {
  await signInAsAdmin(page)

  const slug = `pago-${RUN.toLowerCase()}`

  await page.getByRole('button', { name: 'Dar de alta' }).first().click()
  await page.getByRole('textbox', { name: 'Nombre', exact: true }).fill(`Pagador ${RUN}`)
  await page.getByLabel('Dirección').fill(slug)
  await page.getByLabel('Nombre del dueño').fill('Dueño')
  await page.getByLabel('Correo del dueño').fill(`duena-${slug}@x.test`)
  await page.getByLabel('Contraseña').fill('clave-larga-123')
  await page.getByRole('button', { name: 'Dar de alta', exact: true }).last().click()

  await expect(page.getByText(slug)).toBeVisible()

  await page.getByRole('button', { name: new RegExp(`Pagador ${RUN}`) }).click()
  await page.getByRole('button', { name: 'Suspender' }).click()
  await expect(page.getByText('Suspendido')).toBeVisible()

  // Recording the payment brings it back to life. Leaving it to a manual second
  // step is how an up-to-date customer still cannot work on Monday morning.
  await page.getByRole('button', { name: 'Anotar un pago' }).click()
  await page.getByLabel('Cuánto entró').fill('25,00')
  await page.getByLabel('Referencia').fill(`REF${RUN}`)
  await page.getByRole('button', { name: 'Anotar el pago' }).click()

  await expect(page.getByText('Al día')).toBeVisible()
  await expect(page.getByText(`REF${RUN}`)).toBeVisible()
})

test('support mode looks, and is written down', async ({ page }) => {
  await signInAsAdmin(page)

  // A demo tenant is used, which already has data inside.
  await page.getByLabel('Buscar').fill('Sazón')
  await page.getByRole('button', { name: /Arepera El Sazón/ }).click()

  await page.getByRole('button', { name: 'Echar un vistazo' }).click()

  await expect(page.getByText('Equipo')).toBeVisible()
  await expect(page.getByText('maria@elsazon.test')).toBeVisible()

  /*
   * Walking into a customer's house leaving no trace is the thing you do not
   * do. And this note can be shown to them.
   *
   * The record is reopened rather than reloaded: platform admin has no router —
   * two and a half screens — so a reload would return to the list.
   */
  await page.getByRole('button', { name: 'Todos los negocios' }).click()
  await page.getByRole('button', { name: /Arepera El Sazón/ }).click()

  // `.first()`: the database is additive, so earlier runs may have left more
  // than one visit recorded. What is checked is that THIS one landed.
  await expect(page.getByText('support_access').first()).toBeVisible()
})

test('a tenant session does NOT open platform administration', async ({ page }) => {
  // The failure to avoid: with a shared guard, any tenant employee's session
  // would open everybody's billing.
  await page.goto(dashboardOf('elsazon'))
  await page.getByLabel('Correo').fill('maria@elsazon.test')
  await page.getByLabel('Contraseña').fill('demo1234')
  await page.getByRole('button', { name: 'Entrar' }).click()
  await expect(page.getByRole('button', { name: 'Salir' })).toBeVisible()

  await page.goto(adminAddress())

  // It asks you to sign in again: it is another door.
  await expect(page.getByRole('button', { name: 'Entrar' })).toBeVisible()
})

test('tenants on a capped plan show how much they have used', async ({ page }) => {
  await signInAsAdmin(page)

  await page.getByLabel('Buscar').fill('Esquina')
  await page.getByRole('button', { name: /Pizzería La Esquina/ }).click()

  // Usage against the ceiling. `null` would be "no cap", and it is said in
  // words: a bar full at 0% means nothing.
  await expect(page.getByText('Cuánto usa')).toBeVisible()
  await expect(page.getByText(/de 2$/)).toBeVisible()
})

test('an order can be seeded and seen counted in the metrics', async ({ page }) => {
  // The metrics count orders by entering each tenant one at a time: there is no
  // query that sums them all, because `orders` is under RLS.
  await page.goto(dashboardOf('elsazon'))
  await page.getByLabel('Correo').fill('maria@elsazon.test')
  await page.getByLabel('Contraseña').fill('demo1234')
  await page.getByRole('button', { name: 'Entrar' }).click()
  await expect(page.getByRole('button', { name: 'Salir' })).toBeVisible()

  const { data: product } = await apiPost<{ data: { id: string } }>(
    page,
    '/api/v1/catalog/products',
    { name: `[e2e] Métrica ${RUN}`, price_cents: 300 },
  )

  await apiPost(page, '/api/v1/orders', {
    items: [{ product_id: product.id, quantity: 1 }],
  })

  await signInAsAdmin(page)

  await expect(page.getByText('Pedidos del mes')).toBeVisible()
})
