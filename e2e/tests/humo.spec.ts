import { expect, test } from '@playwright/test'
import { adminAddress, cajaOf, cocinaOf, panelOf, portalOf, TENANTS } from '../support/addresses'
import { apiStatus } from '../support/api'

/*
 * La prueba de humo del andamiaje.
 *
 * No comprueba negocio: comprueba que el reparto por subdominio y por ruta
 * funciona de punta a punta —nginx, los cinco servidores de Vite y la API— y
 * que cada dirección sirve la aplicación que le toca y no otra.
 *
 * Es corta a propósito. Cuando llegue su fase, cada una de estas pantallas
 * tendrá su propio spec recorriéndola de verdad.
 */

const pantallas = [
  { nombre: 'portal del cliente', url: portalOf(TENANTS.arepera), titulo: 'Pedir' },
  { nombre: 'caja', url: cajaOf(TENANTS.arepera), titulo: 'Caja' },
  { nombre: 'panel del dueño', url: panelOf(TENANTS.arepera), titulo: 'Panel' },
  { nombre: 'pantalla de cocina', url: cocinaOf(TENANTS.arepera), titulo: 'Cocina' },
]

for (const pantalla of pantallas) {
  test(`el subdominio de un negocio sirve su ${pantalla.nombre}`, async ({ page }) => {
    await page.goto(pantalla.url)

    // Por rol y por texto visible, nunca por clase ni por data-testid: si un
    // control no se alcanza así, el arreglo está en el componente.
    await expect(page.getByRole('heading', { name: pantalla.titulo })).toBeVisible()
  })
}

test('la super administración vive fuera de los negocios', async ({ page }) => {
  await page.goto(adminAddress())

  await expect(page.getByRole('heading', { name: /Administración/i })).toBeVisible()
})

test('otro negocio entra por su propio subdominio, sin parámetros', async ({ page }) => {
  // El subdominio ES el negocio. Que esto funcione sin tocar nginx, ni el
  // resolutor de las pruebas, ni una lista en ningún sitio, es la razón por la
  // que dar de alta un cliente cuesta una fila en `tenants`.
  await page.goto(portalOf(TENANTS.pizzeria))

  await expect(page.getByRole('heading', { name: 'Pedir' })).toBeVisible()
})

test('la API responde por el mismo origen que el portal', async ({ page }) => {
  // Que `/up` responda 200 desde dentro de la página prueba dos cosas a la
  // vez: que nginx no manda esa ruta a Vite, y que la API está viva detrás del
  // mismo origen —que es lo que permite usar cookie de sesión sin CORS—.
  await page.goto(portalOf(TENANTS.arepera))

  expect(await apiStatus(page, '/up')).toBe(200)
})
