import { expect, test } from '@playwright/test'
import { panelOf, TENANTS } from '../support/addresses'
import { apiFetch, apiPost } from '../support/api'
import { signIn } from '../support/panel'

/*
 * EL REPARTO, LOS CLIENTES Y LA EXPORTACIÓN.
 *
 * Las tres últimas cosas que faltaban para que el plan estuviera entero: quien
 * lleva la comida, la libreta de quién compra, y poder sacar los pedidos a una
 * hoja de cálculo.
 */

const RUN = Date.now().toString(36).slice(-5).toUpperCase()

/** Un pedido a domicilio, listo para que alguien salga con él. */
async function unRepartoListo(
  page: import('@playwright/test').Page,
): Promise<{ id: string; number: number }> {
  const { data: producto } = await apiPost<{ data: { id: string } }>(
    page,
    '/api/v1/catalog/products',
    { name: `[e2e] Reparto ${RUN}`, price_cents: 500 },
  )

  const { data: zonas } = await apiFetch<{ data: { id: string; name: string }[] }>(
    page,
    '/api/v1/delivery/zones',
  )

  const { data: pedido } = await apiPost<{ data: { id: string; number: number } }>(
    page,
    '/api/v1/orders',
    {
      items: [{ product_id: producto.id, quantity: 1 }],
      service_type: 'delivery',
      customer_name: `Cliente ${RUN}`,
      customer_phone: `0414${Date.now().toString().slice(-7)}`,
      delivery_address: `Cuarta avenida, casa ${RUN}`,
      delivery_zone_id: zonas[0]?.id,
    },
  )

  for (const estado of ['confirmed', 'preparing', 'ready']) {
    await apiPost(page, `/api/v1/orders/${pedido.id}/advance`, { status: estado })
  }

  return pedido
}

test('el repartidor toma un pedido, sale y lo entrega', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  const pedido = await unRepartoListo(page)

  await page.goto(panelOf(TENANTS.arepera) + 'entregas')

  await expect(page.getByRole('heading', { name: 'Entregas' })).toBeVisible()

  const tarjeta = page.getByText(`Cuarta avenida, casa ${RUN}`).locator('../..')

  // Lo que hay que cobrar al llegar: 5,00 del plato más el reparto. Es lo único
  // que el repartidor necesita saber del dinero.
  await expect(tarjeta).toContainText('Cobrar')

  await tarjeta.getByRole('button', { name: 'Lo llevo yo' }).click()

  // Pasa a «lo que llevo yo», y desde ahí sale.
  await expect(page.getByRole('button', { name: 'Salgo con él' })).toBeVisible()
  await page.getByRole('button', { name: 'Salgo con él' }).click()

  await page.getByRole('button', { name: 'Entregado' }).click()

  // Sale de la lista: ya no es asunto de nadie.
  await expect(page.getByText(`Cuarta avenida, casa ${RUN}`)).toBeHidden()

  /*
   * Y queda entregado. Se pregunta por ESE pedido y no se busca en la lista: el
   * tablero está acotado, y con el historial de otras corridas por delante el
   * de esta corrida se quedaría fuera legítimamente.
   */
  const { data: entregado } = await apiFetch<{ data: { status: string } }>(
    page,
    `/api/v1/orders/${pedido.id}`,
  )

  expect(entregado.status).toBe('delivered')
})

test('la libreta de clientes se llena sola', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')

  const telefono = `0414${Date.now().toString().slice(-7)}`

  const { data: producto } = await apiPost<{ data: { id: string } }>(
    page,
    '/api/v1/catalog/products',
    { name: `[e2e] Cliente ${RUN}`, price_cents: 300 },
  )

  // Dos pedidos del mismo número: es una sola ficha con dos pedidos.
  for (const vez of [1, 2]) {
    await apiPost(page, '/api/v1/orders', {
      items: [{ product_id: producto.id, quantity: vez }],
      customer_name: `Doña ${RUN}`,
      customer_phone: telefono,
    })
  }

  await page.goto(panelOf(TENANTS.arepera) + 'clientes')

  await page.getByLabel('Buscar').fill(telefono)

  const ficha = page.getByRole('listitem').filter({ hasText: `Doña ${RUN}` })
  await expect(ficha).toContainText('2 pedidos')

  // La nota es lo único que se escribe a mano, y lo que hace que la libreta
  // sirva para algo.
  await ficha.getByRole('button', { name: `Doña ${RUN}` }).click()

  await page.getByLabel('Nota').fill('No le pongan cebolla')
  await page.getByRole('button', { name: 'Guardar la nota' }).click()

  await page.reload()
  await page.getByLabel('Buscar').fill(telefono)
  await page.getByRole('button', { name: `Doña ${RUN}` }).click()

  await expect(page.getByLabel('Nota')).toHaveValue('No le pongan cebolla')
})

test('las ventas se exportan a un archivo', async ({ page }) => {
  // «Un negocio suspendido lee y exporta» está escrito en el middleware; esto
  // es lo que lo hace verdad.
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await page.goto(panelOf(TENANTS.arepera) + 'ventas')

  const descarga = page.waitForEvent('download')

  await page.getByRole('link', { name: 'Exportar' }).click()

  const archivo = await descarga
  expect(archivo.suggestedFilename()).toContain('pedidos-elsazon')
})
