import { expect, test } from '@playwright/test'
import { panelOf, TENANTS } from '../support/addresses'
import { apiFetch, apiPost } from '../support/api'
import { signIn } from '../support/panel'

/*
 * Entrar al panel, por el navegador y de punta a punta.
 *
 * Recorre lo que ninguna prueba de PHP puede recorrer sola: la cookie de CSRF,
 * la sesión sobre el subdominio correcto, y que la pantalla pinte lo que el
 * servidor dijo y nada más.
 */

test('la pantalla de entrar muestra el nombre del NEGOCIO, no el de la plataforma', async ({ page }) => {
  // Es la razón por la que /me responde sin sesión. Un login que dice «Kombo»
  // en vez de «Arepera El Sazón» siembra la duda de si uno está donde cree.
  await page.goto(panelOf(TENANTS.arepera))

  await expect(page.getByRole('heading', { name: 'Arepera El Sazón' })).toBeVisible()
})

test('cada negocio tiene su propia pantalla de entrar', async ({ page }) => {
  await page.goto(panelOf(TENANTS.pizzeria))

  await expect(page.getByRole('heading', { name: 'Pizzería La Esquina' })).toBeVisible()
})

test('la dueña entra y ve su negocio y su nombre', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')

  // En la cabecera del armazón, no en un encabezado de página: quién eres y
  // dónde estás tienen que verse desde cualquier pantalla, no sólo desde la
  // primera.
  await expect(page.getByText('Arepera El Sazón')).toBeVisible()
  await expect(page.getByText('María')).toBeVisible()
})

test('una contraseña que no es, no entra', async ({ page }) => {
  await page.goto(panelOf(TENANTS.arepera))

  await page.getByLabel('Correo').fill('maria@elsazon.test')
  await page.getByLabel('Contraseña').fill('la-que-no-es')
  await page.getByRole('button', { name: 'Entrar' }).click()

  await expect(page.getByRole('alert')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Salir' })).toBeHidden()
})

test('el menú lo decide el servidor, no una lista escrita en React', async ({ page }) => {
  // Afirmar la CAUSA además del síntoma: una prueba que sólo mirase la pantalla
  // pasaría en verde con el menú pintado a mano.
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')

  const capacidades = await apiFetch<{
    user: { name: string }
    permissions: string[]
  }>(page, '/api/v1/me')

  expect(capacidades.user.name).toBe('María')

  const nav = page.getByRole('navigation', { name: 'Secciones' }).first()

  // La dueña puede gestionar la carta y la configuración, así que las ve.
  expect(capacidades.permissions).toContain('catalog.view')
  await expect(nav.getByRole('link', { name: 'Carta' })).toBeVisible()

  expect(capacidades.permissions).toContain('settings.manage')
  await expect(nav.getByRole('link', { name: 'Tasa' })).toBeVisible()
})

test('la cocina no ve las secciones que no le tocan', async ({ page }) => {
  // Carlos sólo tiene la pantalla de comandas. Nada de carta ni de tasa: lo
  // que no aplica NO EXISTE, no aparece en gris.
  await signIn(page, TENANTS.arepera, 'carlos@elsazon.test')

  const nav = page.getByRole('navigation', { name: 'Secciones' }).first()

  await expect(nav.getByRole('link', { name: 'Carta' })).toBeHidden()
  await expect(nav.getByRole('link', { name: 'Tasa' })).toBeHidden()
})

test('salir deja la sesión cerrada de verdad', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await page.getByRole('button', { name: 'Salir' }).click()

  await expect(page.getByRole('button', { name: 'Entrar' })).toBeVisible()

  // Y el servidor tampoco cree que haya alguien dentro.
  const capacidades = await apiFetch<{ user: unknown }>(page, '/api/v1/me')
  expect(capacidades.user).toBeNull()
})

test('salir justo después de tocar algo NO deja la sesión abierta', async ({ page }) => {
  /*
   * El caso raro, y el que muerde en el mostrador.
   *
   * Cada respuesta de Laravel trae su cookie de sesión. Una lectura que salió
   * ANTES de cerrar sesión pero llega DESPUÉS trae la cookie de la sesión
   * anterior, el navegador la aplica, y el cierre queda deshecho: la pantalla
   * sigue dentro con el nombre de quien acaba de irse. En una máquina que se
   * pasan tres personas por turno, eso es la sesión de otra persona.
   *
   * Se provoca como pasa de verdad: confirmar un pedido deja el tablero
   * refrescándose —varias lecturas a la vez— y encima cae el «Salir». Nadie
   * hace esto a propósito; se hace sin querer, con prisa, al terminar el turno.
   */
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')

  const { data: producto } = await apiPost<{ data: { id: string } }>(
    page,
    '/api/v1/catalog/products',
    { name: `[e2e] Salida ${Date.now().toString(36)}`, price_cents: 300 },
  )

  await apiPost(page, '/api/v1/orders', { items: [{ product_id: producto.id, quantity: 1 }] })

  await page.goto(panelOf(TENANTS.arepera) + 'pedidos')

  // Confirmar dispara la revalidación del tablero; el clic de salir le cae
  // encima sin esperar a que termine.
  await page.getByRole('button', { name: 'Confirmar' }).first().click()
  await page.getByRole('button', { name: 'Salir' }).click()

  await expect(page.getByRole('button', { name: 'Entrar' })).toBeVisible()

  // Y el servidor tampoco cree que haya alguien dentro. Si alguna de aquellas
  // lecturas hubiera devuelto la cookie vieja, aquí saldría María.
  const capacidades = await apiFetch<{ user: unknown }>(page, '/api/v1/me')
  expect(capacidades.user).toBeNull()
})
