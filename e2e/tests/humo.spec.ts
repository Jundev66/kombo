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

test('el subdominio de un negocio sirve su portal', async ({ page }) => {
  await page.goto(portalOf(TENANTS.arepera))

  // El portal se presenta con el nombre del NEGOCIO, no con el de la
  // plataforma: quien llega por un enlace de WhatsApp tiene que reconocer al
  // sitio donde come, no enterarse de qué software usan.
  //
  // Por rol y por texto visible, nunca por clase ni por data-testid: si un
  // control no se alcanza así, el arreglo está en el componente.
  await expect(page.getByRole('heading', { name: 'Arepera El Sazón' })).toBeVisible()
})

/*
 * La caja y la cocina se sirven detrás de su puerta, así que lo que hay que
 * comprobar aquí es que cada dirección sirve LA SUYA.
 *
 * Se mira el nombre por defecto del aparato y no un encabezado: el encabezado
 * de la puerta es el nombre del NEGOCIO —quien enciende la máquina tiene que
 * saber que está en el local correcto— y es el mismo en las dos.
 */
const pantallasDelLocal = [
  { nombre: 'caja', url: cajaOf(TENANTS.arepera), aparato: 'Caja' },
  { nombre: 'pantalla de cocina', url: cocinaOf(TENANTS.arepera), aparato: 'Cocina' },
  // El panel no está aquí: ya pide entrar, así que su recorrido vive en
  // `entrar.spec.ts` con su login de verdad.
]

for (const pantalla of pantallasDelLocal) {
  test(`el subdominio de un negocio sirve su ${pantalla.nombre}`, async ({ page }) => {
    await page.goto(pantalla.url)

    await expect(page.getByRole('heading', { name: 'Arepera El Sazón' })).toBeVisible()
    await expect(page.getByLabel('Nombre de la pantalla')).toHaveValue(pantalla.aparato)
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

  // Y sirve LA SUYA: el mismo código, otro negocio, sin un parámetro de por
  // medio.
  await expect(page.getByRole('heading', { name: 'Pizzería La Esquina' })).toBeVisible()
})

test('la API responde por el mismo origen que el portal', async ({ page }) => {
  // Que `/up` responda 200 desde dentro de la página prueba dos cosas a la
  // vez: que nginx no manda esa ruta a Vite, y que la API está viva detrás del
  // mismo origen —que es lo que permite usar cookie de sesión sin CORS—.
  await page.goto(portalOf(TENANTS.arepera))

  expect(await apiStatus(page, '/up')).toBe(200)
})
