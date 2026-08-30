import { expect, test } from '@playwright/test'
import { TENANTS } from '../support/addresses'
import { apiFetch, apiPost } from '../support/api'
import { enterRegister, seedProduct, ticket } from '../support/caja'
import { clearBoard, comanda, enterKitchen } from '../support/cocina'
import { signIn, signOut } from '../support/panel'

/*
 * LA CAJA DEL MOSTRADOR.
 *
 * Es lo que se usa todos los días con un cliente delante, así que las pruebas
 * recorren lo que hace una persona: tocar productos, elegir agregados, cobrar
 * mezclado y entregar el papel. Y comprueban lo único que no se ve desde la
 * caja: que la comanda cayó sola en la cocina.
 */

const RUN = Date.now().toString(36).slice(-5).toUpperCase()

test('cobrar en el mostrador entrega la nota y manda la comanda a la cocina', async ({ page }) => {
  // La sesión del panel sólo se usa para sembrar la carta; la caja entra
  // después por su propia puerta, con PIN.
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  const nombre = `[e2e] Arepa caja ${RUN}`
  await seedProduct(page, nombre, 300)

  // El tablero de cocina se limpia antes: las comandas de corridas anteriores
  // se acumulan, y pasado el tope de la pantalla la nueva no cabría.
  await clearBoard(page)

  await signOut(page)

  await enterRegister(page, TENANTS.arepera, 'Ana', '3456')

  await page.getByRole('button', { name: nombre }).click()
  await expect(ticket(page)).toContainText(nombre)

  await page.getByRole('button', { name: 'Cobrar', exact: true }).click()

  const cobro = page.getByRole('dialog', { name: 'Cobrar' })
  await expect(cobro).toBeVisible()
  await cobro.getByRole('button', { name: 'Cobrar $3,00' }).click()

  // El papel dice lo que es. Las dos frases vienen del servidor, guardadas
  // dentro del propio documento.
  const nota = page.getByRole('dialog')
  await expect(nota).toContainText('NOTA DE ENTREGA')
  await expect(nota).toContainText('No es una factura')
  await expect(nota).toContainText('$3,00')

  // El correlativo va en el título de la nota.
  expect(await nota.getAttribute('aria-label')).toMatch(/^Nota A-\d{6}$/)

  const pedido = /Pedido #(\d+)/.exec(await nota.innerText())
  expect(pedido).not.toBeNull()

  // Y lo que no se ve desde la caja: la comanda cayó sola en la cocina, sin
  // que nadie avisara a nadie.
  //
  // Se borra lo guardado del navegador antes de ir: /caja/ y /cocina/ son el
  // mismo origen, así que sin esto la cocina heredaría el turno de Ana y no se
  // recorrería su puerta de verdad.
  await page.evaluate(() => localStorage.clear())

  await enterKitchen(page, TENANTS.arepera, 'Carlos', '4567')
  await expect(comanda(page, Number(pedido![1]))).toContainText(nombre)
})

test('los agregados se eligen antes de cobrar, y se cobran', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')

  await apiPost(page, '/api/v1/catalog/modifier-groups', {
    name: `[e2e] Punto ${RUN}`,
    // Obligatorio: no se puede seguir sin contestar «¿término de la carne?».
    min_choices: 1,
    max_choices: 1,
    modifiers: [
      { name: `Término medio ${RUN}`, price_delta_cents: 0 },
      { name: `Con todo ${RUN}`, price_delta_cents: 150 },
    ],
  })

  const grupos = await apiFetch<{ data: Array<{ id: string; name: string }> }>(
    page,
    '/api/v1/catalog/modifier-groups',
  )

  const grupo = grupos.data.find((g) => g.name === `[e2e] Punto ${RUN}`)
  expect(grupo).toBeDefined()

  const nombre = `[e2e] Hamburguesa ${RUN}`
  await seedProduct(page, nombre, 500, [grupo!.id])

  await signOut(page)

  await enterRegister(page, TENANTS.arepera, 'Ana', '3456')

  await page.getByRole('button', { name: nombre }).click()

  // La hoja no deja seguir hasta que se contesta lo obligatorio.
  const hoja = page.getByRole('dialog', { name: nombre })
  await expect(hoja.getByRole('button', { name: /Falta elegir/ })).toBeDisabled()

  await hoja.getByRole('radio', { name: `Con todo ${RUN}` }).check()

  // 5,00 + 1,50: el agregado se cobra.
  await hoja.getByRole('button', { name: 'Agregar · $6,50' }).click()

  await expect(ticket(page)).toContainText(`Con todo ${RUN}`)
  await expect(ticket(page)).toContainText('$6,50')
})

test('se cobra mezclado: parte en efectivo y el resto en pago móvil', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  const nombre = `[e2e] Combo mixto ${RUN}`
  await seedProduct(page, nombre, 1000)

  await signOut(page)

  await enterRegister(page, TENANTS.arepera, 'Ana', '3456')

  await page.getByRole('button', { name: nombre }).click()
  await page.getByRole('button', { name: 'Cobrar', exact: true }).click()

  const cobro = page.getByRole('dialog', { name: 'Cobrar' })

  // Cuatro dólares en efectivo…
  await cobro.getByLabel('Monto').fill('4,00')
  await cobro.getByRole('button', { name: 'Agregar este pago' }).click()

  // …y la pantalla dice cuánto falta, sin que haya que restar de cabeza.
  await expect(cobro).toContainText('Falta')
  await expect(cobro).toContainText('$6,00')

  // …el resto por pago móvil, con su referencia.
  await cobro.getByRole('button', { name: 'Pago móvil' }).click()
  await cobro.getByLabel('Referencia').fill(`99${RUN}`)
  await cobro.getByRole('button', { name: 'Agregar este pago' }).click()

  await cobro.getByRole('button', { name: 'Cobrar $10,00' }).click()

  const nota = page.getByRole('dialog')
  await expect(nota).toContainText('Efectivo $')
  await expect(nota).toContainText(`Pago móvil · 99${RUN}`)
  await expect(nota).toContainText('$10,00')
})

test('un negocio sin caja lo dice claro, no falla al cobrar', async ({ page }) => {
  // La pizzería vende sólo por el portal. Su caja no existe, y se dice antes
  // de que nadie arme un pedido entero.
  await enterRegister(page, TENANTS.pizzeria, 'Pedro', '1234', 'pedro@laesquina.test')

  await expect(page.getByRole('heading', { name: 'Este negocio no tiene caja' })).toBeVisible()
})

/*
 * EL DUEÑO SUPERVISA SU PROPIA CAJA.
 *
 * Antes esto no se podía sin volver a identificarse dos veces: acababa de
 * entrar al panel con su contraseña y la caja le pedía dar de alta el aparato y
 * teclear un PIN. Ahora el panel tiene el enlace y la sesión sirve, con la
 * pantalla diciendo con todas las letras a nombre de quién se está vendiendo.
 */
test('el dueño entra a la caja desde el panel, sin alta y sin PIN', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')

  const nombre = `[e2e] Arepa supervisión ${RUN}`
  await seedProduct(page, nombre, 300)

  // Sin `signOut`: la gracia es justo que la sesión del panel valga aquí.
  await page.getByRole('button', { name: 'Más' }).first().click()
  await page.getByRole('link', { name: 'Caja', exact: true }).click()

  // Ni el alta del aparato ni el teclado del PIN.
  await expect(page.getByRole('button', { name: 'Dar de alta' })).toBeHidden()
  await expect(page.getByRole('heading', { name: '¿Quién está en la caja?' })).toBeHidden()

  // Y la pantalla dice quién está operando. No es decoración: lo que se venda
  // aquí lleva ese nombre.
  await expect(page.getByRole('status', { name: 'Supervisión' })).toContainText('Supervisando · María')

  // Vende de verdad, que es lo que distingue supervisar de mirar.
  await page.getByRole('button', { name: nombre }).click()
  await expect(ticket(page)).toContainText(nombre)

  await page.getByRole('button', { name: 'Cobrar', exact: true }).click()

  const cobro = page.getByRole('dialog', { name: 'Cobrar' })
  await cobro.getByRole('button', { name: 'Cobrar $3,00' }).click()

  await expect(page.getByRole('dialog')).toContainText('NOTA DE ENTREGA')
})

test('volver al panel desde la caja no cierra la sesión', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')

  await page.getByRole('button', { name: 'Más' }).first().click()
  await page.getByRole('link', { name: 'Caja', exact: true }).click()

  await expect(page.getByRole('status', { name: 'Supervisión' })).toContainText('Supervisando')

  // No dice «Salir» a propósito: no hay turno que cerrar, y quien llegó desde
  // el panel espera volver al panel y no quedarse en una pantalla vacía.
  await page.getByRole('button', { name: 'Volver al panel' }).click()

  await expect(page.getByRole('button', { name: 'Salir' })).toBeVisible()
  expect(page.url()).toContain('/panel/')
})
