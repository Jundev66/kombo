import { expect, test, type Page } from '@playwright/test'
import { TENANTS } from '../support/addresses'
import { enterRegister, seedProduct } from '../support/caja'
import { signIn, signOut } from '../support/panel'

/*
 * LA NOTA DE ENTREGA.
 *
 * Es un documento COMERCIAL, no fiscal, y el papel lo dice con todas las
 * letras. No sustituye a la factura ni elimina las obligaciones tributarias
 * del negocio: lo que hace este diseño es no FINGIR que emite un documento
 * fiscal.
 *
 * Y un correlativo que se reutiliza no sirve para nada: si dos papeles pueden
 * llevar el mismo número, el número deja de identificar a ninguno.
 */

const RUN = Date.now().toString(36).slice(-5).toUpperCase()

/** Cobra una venta simple y devuelve el número de la nota que salió. */
async function cobrarUna(page: Page, producto: string, importe: string): Promise<number> {
  await page.getByRole('button', { name: producto }).click()
  await page.getByRole('button', { name: 'Cobrar', exact: true }).click()

  const cobro = page.getByRole('dialog', { name: 'Cobrar' })
  await cobro.getByRole('button', { name: `Cobrar ${importe}` }).click()

  const nota = page.getByRole('dialog')
  await expect(nota).toContainText('NOTA DE ENTREGA')

  const referencia = /Nota A-(\d{6})/.exec((await nota.getAttribute('aria-label')) ?? '')
  expect(referencia).not.toBeNull()

  return Number(referencia![1])
}

test('el documento dice que NO es una factura', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  const nombre = `[e2e] Arepa nota ${RUN}`
  await seedProduct(page, nombre, 300)

  await signOut(page)

  await enterRegister(page, TENANTS.arepera, 'Ana', '3456')
  await cobrarUna(page, nombre, '$3,00')

  const nota = page.getByRole('dialog')

  // Encabezado literal, y el aviso justo debajo. Ninguna de las dos cosas es
  // configurable: vienen guardadas dentro del propio documento.
  await expect(nota).toContainText('NOTA DE ENTREGA')
  await expect(nota).toContainText('No es una factura')

  // Y lo que NO tiene: nada que sugiera respaldo fiscal.
  await expect(nota).not.toContainText('FACTURA', { ignoreCase: false })
  await expect(nota).not.toContainText('Número de control')
})

test('el correlativo va uno detrás de otro', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  const nombre = `[e2e] Arepa serie ${RUN}`
  await seedProduct(page, nombre, 300)

  await signOut(page)

  await enterRegister(page, TENANTS.arepera, 'Ana', '3456')

  const primera = await cobrarUna(page, nombre, '$3,00')
  await page.getByRole('button', { name: 'Nueva venta' }).click()

  const segunda = await cobrarUna(page, nombre, '$3,00')

  // Se comprueba la RELACIÓN, no el número: la base es aditiva entre corridas
  // y esperar «A-000001» sería una prueba que sólo pasa la primera vez.
  expect(segunda).toBe(primera + 1)
})

test('anular deja constancia y NO libera el número', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  const nombre = `[e2e] Arepa anulada ${RUN}`
  await seedProduct(page, nombre, 300)

  // Ana es del mostrador: puede INICIAR la anulación, no ejecutarla. Le va a
  // pedir el PIN de José, y la anulación queda a nombre de quien autorizó.
  await signOut(page)

  await enterRegister(page, TENANTS.arepera, 'Ana', '3456')

  const anulada = await cobrarUna(page, nombre, '$3,00')

  await page.getByRole('button', { name: 'Anular esta venta' }).click()
  await page.getByLabel('¿Por qué se anula?').fill(`Se equivocó de pedido ${RUN}`)

  await page.getByLabel('Autoriza').selectOption({ label: 'José · Encargado' })
  await page.getByLabel('PIN').fill('2345')
  await page.getByRole('button', { name: 'Anular', exact: true }).click()

  const nota = page.getByRole('dialog')
  await expect(nota).toContainText('ANULADA')
  await expect(nota).toContainText(`Se equivocó de pedido ${RUN}`)

  // El número anulado sigue siendo suyo: la siguiente venta toma el siguiente.
  await page.getByRole('button', { name: 'Nueva venta' }).click()

  const siguiente = await cobrarUna(page, nombre, '$3,00')
  expect(siguiente).toBe(anulada + 1)
})

test('sin el PIN de un encargado, el mostrador no anula nada', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  const nombre = `[e2e] Arepa sin pin ${RUN}`
  await seedProduct(page, nombre, 300)

  await signOut(page)

  await enterRegister(page, TENANTS.arepera, 'Ana', '3456')

  const numero = await cobrarUna(page, nombre, '$3,00')

  await page.getByRole('button', { name: 'Anular esta venta' }).click()
  await page.getByLabel('¿Por qué se anula?').fill('Sin autorización')

  // El PIN se le pide ANTES de intentarlo, porque `/me` ya dijo que esta
  // acción se lo va a pedir. Con el campo vacío, no anula.
  await page.getByRole('button', { name: 'Anular', exact: true }).click()

  const nota = page.getByRole('dialog')
  await expect(nota).not.toContainText('ANULADA')
  expect(await nota.getAttribute('aria-label')).toBe(
    `Nota A-${String(numero).padStart(6, '0')}`,
  )
})
