import { expect, test } from '@playwright/test'
import { panelOf, TENANTS } from '../support/addresses'
import { apiFetch, apiPost } from '../support/api'
import { signIn } from '../support/panel'

/*
 * El recorrido completo de un pedido, por el navegador.
 *
 * Es el criterio de salida de la fase: recibido → confirmado → en la cocina →
 * listo → entregado, tocando los botones que tocaría una persona.
 */

const RUN = Date.now().toString(36).slice(-5).toUpperCase()

test.beforeEach(async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
})

/** Deja un producto en la carta y devuelve su id. */
async function unProducto(page: import('@playwright/test').Page, nombre: string): Promise<string> {
  const { data } = await apiPost<{ data: { id: string } }>(page, '/api/v1/catalog/products', {
    name: nombre,
    price_cents: 300,
    prep_minutes: 8,
  })

  return data.id
}

/** Deja un pedido recibido y devuelve su número. */
async function unPedido(page: import('@playwright/test').Page, productId: string): Promise<number> {
  const { data } = await apiPost<{ data: { number: number } }>(page, '/api/v1/orders', {
    items: [{ product_id: productId, quantity: 2 }],
    service_type: 'takeaway',
    customer_name: `[e2e] Cliente ${RUN}`,
  })

  return data.number
}

test('un pedido recorre el tablero hasta entregado', async ({ page }) => {
  const producto = await unProducto(page, `[e2e] Arepa ${RUN}`)
  const numero = await unPedido(page, producto)

  await page.goto(panelOf(TENANTS.arepera) + 'pedidos')

  const tarjeta = page.getByRole('listitem').filter({ hasText: `#${numero}` })

  await expect(tarjeta).toBeVisible()
  await expect(tarjeta).toContainText('Sin confirmar')
  await expect(tarjeta).toContainText('2× [e2e] Arepa')

  // Cada paso es un solo botón, y el botón dice lo que va a pasar.
  await tarjeta.getByRole('button', { name: 'Confirmar' }).click()
  await expect(tarjeta).toContainText('Confirmado')

  await tarjeta.getByRole('button', { name: 'A la cocina' }).click()
  await expect(tarjeta).toContainText('En la cocina')

  await tarjeta.getByRole('button', { name: 'Listo' }).click()
  await expect(tarjeta).toContainText('Listo')

  await tarjeta.getByRole('button', { name: 'Entregado' }).click()

  // Entregado sale del tablero: es para trabajar, no para consultar histórico.
  await expect(page.getByRole('listitem').filter({ hasText: `#${numero}` })).toBeHidden()
})

test('el total lo calcula el servidor, no la pantalla', async ({ page }) => {
  // Afirmar la CAUSA además del síntoma. Dos arepas de 3,00 son 6,00 — si la
  // pantalla lo sumara por su cuenta, coincidiría hasta el día que no.
  const producto = await unProducto(page, `[e2e] Arepa total ${RUN}`)
  const numero = await unPedido(page, producto)

  await page.goto(panelOf(TENANTS.arepera) + 'pedidos')

  const tarjeta = page.getByRole('listitem').filter({ hasText: `#${numero}` })
  await expect(tarjeta).toContainText('$6,00')

  const { data } = await apiFetch<{ data: Array<{ number: number; totalCents: number }> }>(
    page,
    '/api/v1/orders?abiertos=1',
  )

  expect(data.find((o) => o.number === numero)?.totalCents).toBe(600)
})

test('se cobra mezclado, y el pago móvil espera a que alguien lo confirme', async ({ page }) => {
  const producto = await unProducto(page, `[e2e] Arepa cobro ${RUN}`)
  const numero = await unPedido(page, producto)

  await page.goto(panelOf(TENANTS.arepera) + 'pedidos')
  await page.getByRole('listitem').filter({ hasText: `#${numero}` }).getByText(`#${numero}`).click()

  // Mitad en efectivo.
  await page.getByLabel('Método de pago').selectOption('cash_usd')
  await page.getByLabel('Cuánto pagó').fill('3,00')
  await page.getByRole('button', { name: 'Registrar el pago' }).click()

  await expect(page.getByText('Falta $3,00')).toBeVisible()

  // La otra mitad por pago móvil: entra esperando revisión.
  await page.getByLabel('Método de pago').selectOption('pago_movil')
  await page.getByLabel('Cuánto pagó').fill('3,00')
  await page.getByLabel('Referencia').fill('004512')
  await page.getByRole('button', { name: 'Registrar el pago' }).click()

  // Todavía debe: el pago móvil no cuenta hasta que alguien mira el
  // comprobante y dice que sí. No hay API bancaria que preguntar.
  await expect(page.getByText('Falta $3,00')).toBeVisible()

  // «Confirmar el pago», no «Confirmar»: en esta misma pantalla hay un botón
  // que confirma el PEDIDO, y son cosas distintas. El nombre lo desambigua
  // para la prueba y, sobre todo, para quien lo pulsa.
  await page.getByRole('button', { name: 'Confirmar el pago' }).click()

  await expect(page.getByText('Falta $3,00')).toBeHidden()
  await expect(page.getByText('Ref. 004512')).toBeVisible()
})

test('cancelar exige un motivo', async ({ page }) => {
  const producto = await unProducto(page, `[e2e] Arepa cancelada ${RUN}`)
  const numero = await unPedido(page, producto)

  await page.goto(panelOf(TENANTS.arepera) + 'pedidos')
  await page.getByRole('listitem').filter({ hasText: `#${numero}` }).getByText(`#${numero}`).click()

  // Sin motivo, el botón no deja: cancelar es la vía natural para sacar comida
  // sin cobrarla, y al final del mes alguien va a preguntar por qué hubo
  // catorce.
  const botón = page.getByRole('button', { name: 'Cancelar el pedido' })
  await expect(botón).toBeDisabled()

  await page.getByLabel('Motivo').fill('El cliente se arrepintió')
  await expect(botón).toBeEnabled()
  await botón.click()

  await expect(page.getByRole('listitem').filter({ hasText: `#${numero}` })).toBeHidden()
})
