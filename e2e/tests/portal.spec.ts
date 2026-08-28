import { expect, test } from '@playwright/test'
import { TENANTS } from '../support/addresses'
import { apiPost } from '../support/api'
import { comanda, enterKitchen } from '../support/cocina'
import { signIn, signOut } from '../support/panel'
import { addToCart, cartBar, openMenu, trackAddress } from '../support/portal'

/*
 * UN PEDIDO COMPLETO DESDE EL TELÉFONO, SIN CUENTA.
 *
 * Es el criterio de salida de la fase: alguien que llega por un enlace de
 * WhatsApp mira la carta, arma su pedido, lo manda, y esa comanda aparece sola
 * en la cocina.
 *
 * Las pruebas del portal **no entran a ninguna sesión**: quien está del otro
 * lado es alguien de la calle. Cuando hace falta sembrar la carta se entra al
 * panel, se siembra, y se SALE antes de tocar el portal.
 */

const RUN = Date.now().toString(36).slice(-5).toUpperCase()

test('un pedido desde el teléfono llega a la cocina, sin cuenta', async ({ page }) => {
  const nombre = `[e2e] Arepa portal ${RUN}`

  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await apiPost(page, '/api/v1/catalog/products', {
    name: nombre,
    price_cents: 350,
    prep_minutes: 8,
  })
  await signOut(page)

  // A partir de aquí, nadie ha entrado a nada.
  await openMenu(page, TENANTS.arepera)

  await expect(page.getByRole('heading', { name: 'Arepera El Sazón' })).toBeVisible()
  await expect(page.getByText('Abierto ahora')).toBeVisible()

  await addToCart(page, nombre, 2)

  await expect(cartBar(page)).toContainText('$7,00')
  await cartBar(page).click()

  // El checkout va en un solo scroll: sin pasos ni «siguiente».
  await expect(page.getByRole('heading', { name: 'Tu pedido' })).toBeVisible()

  await page.getByRole('button', { name: 'Lo busco' }).click()
  await page.getByLabel('¿Cómo te llamas?').fill(`Cliente ${RUN}`)
  await page.getByLabel('Teléfono').fill('04141234567')
  await page.getByRole('button', { name: 'Efectivo al recibir' }).click()

  await page.getByRole('button', { name: /Hacer el pedido/ }).click()

  // Se acaba en el seguimiento, y el enlace queda en la barra de direcciones:
  // es lo que le permite volver mañana a ver qué pasó.
  await expect(page).toHaveURL(/\/p\/[A-Za-z0-9]+$/)
  await expect(page.getByText('Recibido, ya lo vemos')).toBeVisible()

  const numero = /Pedido #(\d+)/.exec(await page.getByRole('heading', { level: 1 }).innerText())
  expect(numero).not.toBeNull()

  // Todavía NO está en la cocina: el negocio tiene que confirmarlo primero.
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await page.goto(`http://${TENANTS.arepera}.localhost:8010/panel/pedidos`)

  const fila = page.getByRole('listitem').filter({ hasText: `#${numero![1]}` })
  await expect(fila).toContainText(`Cliente ${RUN}`)

  await fila.getByRole('button', { name: 'Confirmar' }).click()
  await signOut(page)

  // Y ahora sí: la comanda apareció sola en la cocina.
  await page.evaluate(() => localStorage.clear())
  await enterKitchen(page, TENANTS.arepera, 'Carlos', '4567')

  await expect(comanda(page, Number(numero![1]))).toContainText(nombre)
})

test('el reparto cobra la tarifa de la zona, y el cliente la ve antes de pedir', async ({ page }) => {
  const nombre = `[e2e] Arepa reparto ${RUN}`

  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await apiPost(page, '/api/v1/catalog/products', { name: nombre, price_cents: 500 })
  await signOut(page)

  await openMenu(page, TENANTS.arepera)
  await addToCart(page, nombre)
  await cartBar(page).click()

  await page.getByRole('button', { name: 'Me lo traen' }).click()

  // La tarifa y los minutos van EN la opción: se eligen sabiendo cuánto cuesta
  // y cuánto tarda, no después.
  const zona = page.getByLabel('¿A qué zona?')
  const palosGrandes = await zona
    .locator('option')
    .filter({ hasText: 'Los Palos Grandes' })
    .getAttribute('value')

  await zona.selectOption(palosGrandes ?? '')

  // La tarifa y los minutos van EN la opción: se elige sabiendo cuánto cuesta.
  await expect(zona).toContainText('$2,00')

  await page.getByLabel('Dirección').fill(`Cuarta avenida, casa ${RUN}`)
  await page.getByLabel('¿Cómo te llamas?').fill(`Cliente ${RUN}`)
  await page.getByLabel('Teléfono').fill('04141234567')

  // 5,00 del producto + 2,00 del reparto. El total lo dice el botón.
  await expect(page.getByRole('button', { name: /Hacer el pedido/ })).toContainText('$7,00')
})

test('el botón dice QUÉ FALTA, no sólo que no se puede', async ({ page }) => {
  const nombre = `[e2e] Arepa falta ${RUN}`

  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await apiPost(page, '/api/v1/catalog/products', { name: nombre, price_cents: 300 })
  await signOut(page)

  await openMenu(page, TENANTS.arepera)
  await addToCart(page, nombre)
  await cartBar(page).click()

  // Un botón gris sin explicación deja a alguien mirando la pantalla sin saber
  // qué tocar.
  await expect(page.getByRole('button', { name: 'Falta tu nombre' })).toBeDisabled()

  await page.getByLabel('¿Cómo te llamas?').fill(`Cliente ${RUN}`)
  await expect(page.getByRole('button', { name: 'Falta tu teléfono' })).toBeDisabled()

  await page.getByLabel('Teléfono').fill('04141234567')
  await expect(page.getByRole('button', { name: /Hacer el pedido/ })).toBeEnabled()
})

test('el carrito sobrevive a que el cliente cierre y vuelva', async ({ page }) => {
  // Le entra una llamada mientras mira el menú y vuelve diez minutos después.
  // Encontrar el carrito vacío es tener que empezar otra vez.
  const nombre = `[e2e] Arepa carrito ${RUN}`

  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await apiPost(page, '/api/v1/catalog/products', { name: nombre, price_cents: 300 })
  await signOut(page)

  await openMenu(page, TENANTS.arepera)
  await addToCart(page, nombre, 3)
  await expect(cartBar(page)).toContainText('$9,00')

  await page.reload()

  await expect(cartBar(page)).toContainText('$9,00')
})

test('el pago móvil pide el comprobante y dice a quién pagarle', async ({ page }) => {
  const nombre = `[e2e] Arepa transferida ${RUN}`

  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await apiPost(page, '/api/v1/catalog/products', { name: nombre, price_cents: 400 })
  await signOut(page)

  await openMenu(page, TENANTS.arepera)
  await addToCart(page, nombre)
  await cartBar(page).click()

  await page.getByRole('button', { name: 'Lo busco' }).click()
  await page.getByLabel('¿Cómo te llamas?').fill(`Cliente ${RUN}`)
  await page.getByLabel('Teléfono').fill('04141234567')
  await page.getByRole('button', { name: 'Pago móvil', exact: true }).click()

  // A dónde manda el dinero, ANTES de pedir. Un botón de pagar que no dice a
  // quién pagarle es una llamada de teléfono garantizada.
  await expect(page.getByText(/Banco de Venezuela/)).toBeVisible()

  await page.getByRole('button', { name: /Hacer el pedido/ }).click()

  await expect(page.getByText('Esperando tu comprobante')).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Falta tu comprobante' })).toBeVisible()
})

test('el enlace del pedido lo abre cualquiera que lo tenga, y sólo ése', async ({ page }) => {
  const nombre = `[e2e] Arepa enlace ${RUN}`

  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await apiPost(page, '/api/v1/catalog/products', { name: nombre, price_cents: 300 })
  await signOut(page)

  await openMenu(page, TENANTS.arepera)
  await addToCart(page, nombre)
  await cartBar(page).click()

  await page.getByRole('button', { name: 'Lo busco' }).click()
  await page.getByLabel('¿Cómo te llamas?').fill(`Cliente ${RUN}`)
  await page.getByLabel('Teléfono').fill('04141234567')
  await page.getByRole('button', { name: /Hacer el pedido/ }).click()

  await expect(page).toHaveURL(/\/p\//)
  const token = page.url().split('/p/')[1] ?? ''

  // Se borra TODO lo del navegador: el enlace tiene que valerse solo, que es
  // justo lo que se necesita cuando el cliente se fue a la aplicación del banco.
  await page.context().clearCookies()
  await page.evaluate(() => localStorage.clear())

  await page.goto(trackAddress(TENANTS.arepera, token))
  await expect(page.getByText('Recibido, ya lo vemos')).toBeVisible()

  // Y un token inventado no abre nada: 404, nunca una pantalla de otro pedido.
  await page.goto(trackAddress(TENANTS.arepera, 'noExisteEsteTokenLargo1'))
  await expect(page.getByRole('heading', { name: 'No encontramos ese pedido' })).toBeVisible()
})
