import { expect, test } from '@playwright/test'
import { panelOf, TENANTS } from '../support/addresses'
import { apiFetch } from '../support/api'
import { signIn } from '../support/panel'

/*
 * Cargar la carta desde el panel, como lo haría el dueño con el teléfono.
 *
 * Es el criterio de salida de la fase: si esto no se puede hacer, no hay nada
 * que vender ni nada que mandar a la cocina.
 */

// Lo que esta corrida crea va marcado: la siembra es ADITIVA y no borra nada,
// así que sin marca la segunda corrida encontraría los productos de la primera.
const RUN = Date.now().toString(36).slice(-5).toUpperCase()

test.beforeEach(async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
})

test('la dueña añade un producto a la carta', async ({ page }) => {
  const nombre = `[e2e] Reina Pepiada ${RUN}`

  await page.goto(panelOf(TENANTS.arepera) + 'carta/nuevo')

  await page.getByLabel('Nombre').fill(nombre)
  await page.getByLabel('Precio en dólares').fill('3,50')
  await page.getByLabel('Minutos que tarda').fill('8')
  await page.getByRole('button', { name: 'Guardar' }).click()

  // Vuelve a la carta y el producto está ahí, con su precio.
  await expect(page.getByText(nombre)).toBeVisible()
  await expect(page.getByText('$3,50').first()).toBeVisible()
})

test('el precio se guarda en centavos, no en coma flotante', async ({ page }) => {
  // Afirmar la CAUSA además del síntoma: que la pantalla diga «$3,50» no
  // prueba que se guardó bien. 3.5 en coma flotante acaba en un cuadre que no
  // cierra tres meses después.
  const nombre = `[e2e] Tequeños ${RUN}`

  await page.goto(panelOf(TENANTS.arepera) + 'carta/nuevo')
  await page.getByLabel('Nombre').fill(nombre)
  await page.getByLabel('Precio en dólares').fill('3,50')
  await page.getByRole('button', { name: 'Guardar' }).click()
  await expect(page.getByText(nombre)).toBeVisible()

  const { data } = await apiFetch<{ data: Array<{ name: string; priceCents: number }> }>(
    page,
    '/api/v1/catalog/products?buscar=Teque',
  )

  const creado = data.find((p) => p.name === nombre)
  expect(creado?.priceCents).toBe(350)
})

test('la carta vacía dice qué hacer, no sólo que está vacía', async ({ page }) => {
  // Una lista vacía que sólo dice «no hay productos» deja a alguien buscando
  // dónde se crean. Se comprueba en el negocio que no tiene carta cargada.
  await signIn(page, TENANTS.pizzeria, 'pedro@laesquina.test')
  await page.goto(panelOf(TENANTS.pizzeria) + 'carta')

  await expect(page.getByRole('link', { name: /Añadir el primero/i })).toBeVisible()
})

test('un grupo de agregados se crea con sus opciones de una vez', async ({ page }) => {
  // Un grupo sin opciones es una pregunta sin respuestas en la carta.
  await page.goto(panelOf(TENANTS.arepera) + 'agregados')

  // `exact: true` en las opciones: getByLabel hace coincidencia PARCIAL y sin
  // distinguir mayúsculas, así que «Opción 1» también encontraría «Precio de
  // la opción 1». Es la trampa clásica de los nombres que se contienen.

  await page.getByLabel('La pregunta').fill(`[e2e] Extras ${RUN}`)
  await page.getByLabel('Opción 1', { exact: true }).fill('Sin cebolla')
  await page.getByRole('button', { name: 'Otra opción' }).click()
  await page.getByLabel('Opción 2', { exact: true }).fill('Extra queso')
  await page.getByLabel('Precio de la opción 2').fill('0,50')
  await page.getByRole('button', { name: 'Guardar el grupo' }).click()

  // Acotado al grupo de ESTA corrida. La siembra es aditiva y no borra nada,
  // así que «Sin cebolla» a secas encontraría también los de corridas
  // anteriores y Playwright fallaría por ambigüedad — que es su forma de
  // avisar de que la prueba estaba mal escrita, no de que el sistema falle.
  const grupo = page.getByRole('listitem').filter({ hasText: `[e2e] Extras ${RUN}` })

  await expect(grupo).toBeVisible()
  await expect(grupo.getByText('Sin cebolla')).toBeVisible()
  await expect(grupo.getByText('Extra queso $0,50')).toBeVisible()
})

test('la tasa del día se carga y se ve aplicada', async ({ page }) => {
  await page.goto(panelOf(TENANTS.arepera) + 'tasa')

  await page.getByLabel('Bolívares por dólar').fill('36,50')
  await page.getByRole('button', { name: 'Guardar la tasa' }).click()

  await expect(page.getByText('Bs 36,5 por dólar')).toBeVisible()

  // Y la comprobación con un importe real: 100 $ a 36,50 son Bs 3.650,00.
  // Un «36,5» suelto no delata un cero de más; esto sí.
  await expect(page.getByText('Bs 3.650,00')).toBeVisible()
})

test('sin tasa cargada, la carta lo avisa antes de que sea un problema', async ({ page }) => {
  // La pizzería no ha cargado tasa. Descubrirlo con un cliente delante es
  // tarde.
  await signIn(page, TENANTS.pizzeria, 'pedro@laesquina.test')
  await page.goto(panelOf(TENANTS.pizzeria) + 'carta')

  await expect(page.getByRole('alert')).toContainText('tasa del día')
})
