import { expect, test } from '@playwright/test'
import { cocinaOf, panelOf, PASSWORD, TENANTS } from '../support/addresses'
import { apiFetch, apiPost } from '../support/api'
import { clearBoard, comanda, enterKitchen } from '../support/cocina'
import { signIn, signOut } from '../support/panel'

/*
 * EL RECORRIDO QUE MOTIVÓ EL PROYECTO.
 *
 * Confirmar un pedido en el panel hace que la comanda aparezca sola en la
 * pantalla de cocina; marcarla lista la saca. Dos pantallas distintas, en dos
 * máquinas distintas, y nadie avisando a nadie.
 *
 * foodRun tenía el ayudante para entrar a /cocina/ y ni un solo spec que la
 * recorriera: la cocina sólo se probaba por API. Éste es justo el hueco que
 * aquí no podía quedar.
 */

const RUN = Date.now().toString(36).slice(-5).toUpperCase()

async function unPedidoConfirmado(
  page: import('@playwright/test').Page,
  nombre: string,
  modificadores: string[] = [],
): Promise<number> {
  const { data: producto } = await apiPost<{ data: { id: string } }>(
    page,
    '/api/v1/catalog/products',
    { name: nombre, price_cents: 300, prep_minutes: 8 },
  )

  const { data: pedido } = await apiPost<{ data: { id: string; number: number } }>(
    page,
    '/api/v1/orders',
    {
      items: [{ product_id: producto.id, quantity: 2, modifier_ids: modificadores }],
      service_type: 'takeaway',
    },
  )

  await apiPost(page, `/api/v1/orders/${pedido.id}/advance`, { status: 'confirmed' })

  return pedido.number
}

test('confirmar en el panel hace aparecer la comanda en la cocina', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await clearBoard(page)
  const numero = await unPedidoConfirmado(page, `[e2e] Arepa cocina ${RUN}`)

  // Otra pantalla, OTRA SESIÓN. Cerrar la del panel no es limpieza: Sanctum
  // prefiere la cookie al token, así que sin esto la prueba teclea el PIN de
  // Carlos y opera como María — y pasaría en verde justo donde tenía que
  // atrapar un permiso de menos.
  await signOut(page)
  await enterKitchen(page, TENANTS.arepera, 'Carlos', '4567')

  const tarjeta = comanda(page, numero)

  await expect(tarjeta).toBeVisible()
  await expect(tarjeta).toContainText('2× [e2e] Arepa cocina')
  await expect(tarjeta).toContainText('Para llevar')
})

test('la comanda avanza de un toque y al final sale de la pantalla', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await clearBoard(page)
  const numero = await unPedidoConfirmado(page, `[e2e] Arepa avanza ${RUN}`)

  await signOut(page)
  await enterKitchen(page, TENANTS.arepera, 'Carlos', '4567')

  const tarjeta = comanda(page, numero)

  // El botón dice lo que va a pasar, no el estado al que va.
  await tarjeta.getByRole('button', { name: 'Empezar' }).click()
  await expect(tarjeta.getByRole('button', { name: 'Listo' })).toBeVisible()

  await tarjeta.getByRole('button', { name: 'Listo' }).click()
  await expect(tarjeta.getByRole('button', { name: 'Entregado' })).toBeVisible()

  await tarjeta.getByRole('button', { name: 'Entregado' }).click()

  // Sale de la cocina: el histórico es cosa de reportes, y una pantalla con lo
  // de ayer es una pantalla que nadie mira.
  await expect(comanda(page, numero)).toBeHidden()
})

test('los agregados se leen en la comanda, no hay que ir a buscarlos', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await clearBoard(page)

  await apiPost(page, '/api/v1/catalog/modifier-groups', {
    name: `[e2e] Extras cocina ${RUN}`,
    modifiers: [{ name: `Sin cebolla ${RUN}` }],
  })

  // El grupo se crea con sus opciones de una vez, pero hace falta el id del
  // modificador para pedirlo. Se pregunta en vez de deducirlo.
  const grupos = await apiFetch<{
    data: Array<{ modifiers: Array<{ id: string; name: string }> }>
  }>(page, '/api/v1/catalog/modifier-groups')

  const sinCebolla = grupos.data
    .flatMap((g) => g.modifiers)
    .find((m) => m.name === `Sin cebolla ${RUN}`)

  expect(sinCebolla).toBeDefined()

  const numero = await unPedidoConfirmado(
    page,
    `[e2e] Arepa agregados ${RUN}`,
    [sinCebolla!.id],
  )

  await signOut(page)
  await enterKitchen(page, TENANTS.arepera, 'Carlos', '4567')

  // En TEXTO, listo para leer mientras se cocina. No un identificador que
  // habría que ir a buscar con las manos ocupadas.
  await expect(comanda(page, numero)).toContainText(`Sin cebolla ${RUN}`)
})

test('el cocinero sólo tiene la cocina: no llega al panel', async ({ page }) => {
  // Carlos entra a su pantalla y nada más. Lo que no aplica no existe.
  // Aquí no hay sesión de panel que cerrar: se llega directo a la cocina.
  await enterKitchen(page, TENANTS.arepera, 'Carlos', '4567')
  await expect(page.getByRole('heading', { name: 'Cocina' })).toBeVisible()

  await page.goto(panelOf(TENANTS.arepera))

  // El panel usa sesión por cookie, no el token de la tablet: pide entrar.
  await expect(page.getByRole('button', { name: 'Entrar' })).toBeVisible()
})

test('un PIN que no es, no abre la cocina', async ({ page }) => {
  await page.goto(cocinaOf(TENANTS.arepera))

  if (await page.getByRole('button', { name: 'Dar de alta' }).isVisible()) {
    await page.getByLabel('Correo').fill('maria@elsazon.test')
    await page.getByLabel('Contraseña').fill(PASSWORD)
    await page.getByRole('button', { name: 'Dar de alta' }).click()
  }

  await page.getByRole('button', { name: 'Carlos' }).click()

  for (const digit of '0000') {
    await page.getByRole('button', { name: digit, exact: true }).click()
  }

  await expect(page.getByRole('alert')).toContainText('Ese PIN no es')
})
