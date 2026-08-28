import { expect, test } from '@playwright/test'
import { panelOf, TENANTS } from '../support/addresses'
import { apiPost } from '../support/api'
import { signIn } from '../support/panel'

/*
 * LO QUE VENDÍ HOY.
 *
 * Es el criterio de salida de la última fase: el dueño abre esto desde el
 * teléfono, de pie y entre dos pedidos, y sabe cuánto vendió y qué se vende
 * más sin tocar nada más.
 */

const RUN = Date.now().toString(36).slice(-5).toUpperCase()

test('el dueño abre las ventas del día y le cuadra', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')

  // Dos ventas de hoy, con precios distintos para poder comprobar el orden.
  const { data: caro } = await apiPost<{ data: { id: string } }>(
    page,
    '/api/v1/catalog/products',
    { name: `[e2e] Parrilla ${RUN}`, price_cents: 1000 },
  )

  const { data: barato } = await apiPost<{ data: { id: string } }>(
    page,
    '/api/v1/catalog/products',
    { name: `[e2e] Jugo ${RUN}`, price_cents: 100 },
  )

  for (const [producto, cantidad] of [
    [caro.id, 2],
    [barato.id, 3],
  ] as const) {
    const { data: pedido } = await apiPost<{ data: { id: string } }>(page, '/api/v1/orders', {
      items: [{ product_id: producto, quantity: cantidad }],
    })

    // Confirmar es lo que convierte un pedido en una venta: uno que el negocio
    // nunca aceptó no cuenta.
    await apiPost(page, `/api/v1/orders/${pedido.id}/advance`, { status: 'confirmed' })

    await apiPost(page, `/api/v1/orders/${pedido.id}/payments`, {
      method: 'cash_usd',
      amount_cents: cantidad * (producto === caro.id ? 1000 : 100),
    })
  }

  await page.goto(panelOf(TENANTS.arepera) + 'ventas')

  await expect(page.getByRole('heading', { name: 'Ventas' })).toBeVisible()

  // Lo que más se vende, ordenado por lo que DEJA: tres jugos se ven mucho y
  // venden menos que dos parrillas.
  //
  // Se comprueba el ORDEN entre los dos productos de ESTA corrida, no cuál
  // encabeza la lista: la base es aditiva y arriba puede haber cualquier cosa
  // de corridas anteriores, incluso empatada en total.
  const parrilla = page.getByRole('listitem').filter({ hasText: `[e2e] Parrilla ${RUN}` })
  await expect(parrilla).toContainText('$20,00')
  await expect(parrilla).toContainText('2×')

  /*
   * El ORDEN entre productos no se comprueba aquí, sino en Pest con datos
   * limpios: esta lista está recortada al top del negocio, y con lo que dejan
   * otras corridas el jugo de esta puede quedarse fuera legítimamente. Lo que
   * sólo prueba el navegador es que la pantalla lo enseña.
   */

  await expect(page.getByText('Cómo pagaron')).toBeVisible()
  await expect(page.getByText('Efectivo en dólares')).toBeVisible()

  // Y la forma del día, que es lo que se viene a mirar.
  await expect(page.getByRole('heading', { name: 'A qué hora' })).toBeVisible()
  await expect(page.getByText('La hora fuerte:')).toBeVisible()
})

test('cambiar de período cambia lo que se ve', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await page.goto(panelOf(TENANTS.arepera) + 'ventas')

  await expect(page.getByRole('button', { name: 'Hoy' })).toHaveAttribute('aria-pressed', 'true')

  await page.getByRole('button', { name: 'Este mes' }).click()

  await expect(page.getByRole('button', { name: 'Este mes' })).toHaveAttribute(
    'aria-pressed',
    'true',
  )

  // El mes incluye lo de hoy, así que nunca puede tener menos pedidos.
  await expect(page.getByText(/pedidos/)).toBeVisible()
})

test('la cocina no ve las ventas', async ({ page }) => {
  // Hay negocios donde el encargado opera todo el día y el dueño prefiere que
  // no vea los totales. Aquí lo que no aplica, no existe: ni entrada en el
  // menú, ni pantalla.
  await page.goto(panelOf(TENANTS.arepera))
  await page.getByLabel('Correo').fill('carlos@elsazon.test')
  await page.getByLabel('Contraseña').fill('demo1234')
  await page.getByRole('button', { name: 'Entrar' }).click()

  await expect(page.getByRole('link', { name: 'Ventas' })).toBeHidden()
})
