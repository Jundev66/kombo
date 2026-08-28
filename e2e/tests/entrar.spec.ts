import { expect, test } from '@playwright/test'
import { panelOf, TENANTS } from '../support/addresses'
import { apiFetch } from '../support/api'
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

test('la dueña entra y ve su negocio', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')

  await expect(page.getByRole('heading', { name: 'Arepera El Sazón' })).toBeVisible()
  await expect(page.getByText('María · Dueño')).toBeVisible()
})

test('una contraseña que no es, no entra', async ({ page }) => {
  await page.goto(panelOf(TENANTS.arepera))

  await page.getByLabel('Correo').fill('maria@elsazon.test')
  await page.getByLabel('Contraseña').fill('la-que-no-es')
  await page.getByRole('button', { name: 'Entrar' }).click()

  await expect(page.getByRole('alert')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Salir' })).toBeHidden()
})

test('lo que se ve en pantalla es lo que dijo el servidor', async ({ page }) => {
  // Afirmar la CAUSA además del síntoma. Una prueba que sólo mira la pantalla
  // pasaría en verde con los permisos pintados a mano en React.
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')

  const capacidades = await apiFetch<{ user: { name: string }; modules: string[] }>(
    page,
    '/api/v1/me',
  )

  expect(capacidades.user.name).toBe('María')

  for (const modulo of capacidades.modules) {
    await expect(page.getByText(modulo === 'core' ? 'Configuración' : modulo)).toBeVisible()
  }
})

test('salir deja la sesión cerrada de verdad', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await page.getByRole('button', { name: 'Salir' }).click()

  await expect(page.getByRole('button', { name: 'Entrar' })).toBeVisible()

  // Y el servidor tampoco cree que haya alguien dentro.
  const capacidades = await apiFetch<{ user: unknown }>(page, '/api/v1/me')
  expect(capacidades.user).toBeNull()
})
