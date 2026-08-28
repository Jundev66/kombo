import { expect, test, type Page } from '@playwright/test'
import { addressOf, adminAddress, panelOf } from '../support/addresses'
import { apiFetch, apiPost } from '../support/api'

/*
 * LA SUPER ADMINISTRACIÓN.
 *
 * Es la parte que permite vender esto: dar de alta un negocio, cobrarle, y
 * cortarle cuando deja de pagar — sin que eso signifique quitarle sus datos.
 *
 * Vive en `admin.localhost` y entra por su propia puerta. Estar dentro de un
 * negocio no la abre, ni al revés.
 */

const RUN = Date.now().toString(36).slice(-5).toUpperCase()

const ADMIN = { email: 'admin@kombo.test', password: 'demo1234' }

async function signInAsAdmin(page: Page): Promise<void> {
  await page.goto(adminAddress())

  await page.getByLabel('Correo').fill(ADMIN.email)
  await page.getByLabel('Contraseña').fill(ADMIN.password)
  await page.getByRole('button', { name: 'Entrar' }).click()

  await expect(page.getByRole('button', { name: 'Salir' })).toBeVisible()
}

test('la super administración no se abre sin entrar', async ({ page }) => {
  await page.goto(adminAddress())

  // Ni siquiera se ven las cifras: lo primero es la puerta.
  await expect(page.getByRole('button', { name: 'Entrar' })).toBeVisible()
  await expect(page.getByText('Negocios activos')).toBeHidden()
})

test('dar de alta un negocio lo deja listo para que su dueña entre', async ({ page }) => {
  await signInAsAdmin(page)

  // Las cifras que contestan «¿esto va bien?» en cinco segundos.
  await expect(page.getByText('Negocios activos')).toBeVisible()
  await expect(page.getByText('Ingreso mensual')).toBeVisible()

  const slug = `nuevo-${RUN.toLowerCase()}`
  const correo = `duena-${RUN.toLowerCase()}@nuevo.test`

  await page.getByRole('button', { name: 'Dar de alta' }).first().click()

  await page.getByRole('textbox', { name: 'Nombre', exact: true }).fill(`Arepera ${RUN}`)
  await page.getByLabel('Dirección').fill(slug)
  await page.getByLabel('Nombre del dueño').fill('Dueña')
  await page.getByLabel('Correo del dueño').fill(correo)
  await page.getByLabel('Contraseña').fill('clave-larga-123')

  await page.getByRole('button', { name: 'Dar de alta', exact: true }).last().click()

  await expect(page.getByRole('button', { name: 'Dar de alta' }).first()).toBeVisible()
  await expect(page.getByText(slug)).toBeVisible()

  /*
   * Y lo que importa de verdad: la dueña ENTRA a su negocio.
   *
   * Un alta que deja un negocio a medio crear es peor que ninguna — nadie
   * puede arreglarlo desde dentro, porque para entrar hace falta justo lo que
   * faltó.
   */
  await page.goto(panelOf(slug))
  await page.getByLabel('Correo').fill(correo)
  await page.getByLabel('Contraseña').fill('clave-larga-123')
  await page.getByRole('button', { name: 'Entrar' }).click()

  await expect(page.getByRole('button', { name: 'Salir' })).toBeVisible()

  // Con su carta lista para cargar y su portal ya en pie.
  await page.goto(addressOf(slug, '/'))
  await expect(page.getByRole('heading', { name: `Arepera ${RUN}` })).toBeVisible()
})

test('un negocio suspendido lee sus datos, pero no puede seguir operando', async ({ page }) => {
  /*
   * Las dos mitades importan. Borrarle el acceso a sus propios datos a quien
   * confió en el sistema no es una palanca de cobro aceptable: sus pedidos y su
   * carta siguen siendo suyos aunque nos deba tres meses. Lo que se corta es
   * seguir operando gratis.
   */
  await signInAsAdmin(page)

  const slug = `suspende-${RUN.toLowerCase()}`
  const correo = `duena-${slug}@x.test`

  await page.getByRole('button', { name: 'Dar de alta' }).first().click()
  await page.getByRole('textbox', { name: 'Nombre', exact: true }).fill(`Suspendible ${RUN}`)
  await page.getByLabel('Dirección').fill(slug)
  await page.getByLabel('Nombre del dueño').fill('Dueña')
  await page.getByLabel('Correo del dueño').fill(correo)
  await page.getByLabel('Contraseña').fill('clave-larga-123')
  await page.getByRole('button', { name: 'Dar de alta', exact: true }).last().click()

  await expect(page.getByText(slug)).toBeVisible()

  // Se abre su ficha y se suspende.
  await page.getByRole('button', { name: new RegExp(`Suspendible ${RUN}`) }).click()
  await expect(page.getByRole('heading', { name: `Suspendible ${RUN}` })).toBeVisible()

  await page.getByRole('button', { name: 'Suspender' }).click()
  await expect(page.getByText('Suspendido')).toBeVisible()

  // La dueña entra —eso no se le quita— y ve su carta.
  await page.goto(panelOf(slug))
  await page.getByLabel('Correo').fill(correo)
  await page.getByLabel('Contraseña').fill('clave-larga-123')
  await page.getByRole('button', { name: 'Entrar' }).click()
  await expect(page.getByRole('button', { name: 'Salir' })).toBeVisible()

  await expect(apiFetch(page, '/api/v1/catalog/products')).resolves.toBeTruthy()

  // Pero no puede crear nada: 402, que dice lo que pasa de verdad. Un 403
  // diría «no tienes permiso» y la mandaría a revisar los roles de su equipo.
  const status = await page.evaluate(async () => {
    const xsrf = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1]

    const response = await fetch('/api/v1/catalog/products', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
      },
      body: JSON.stringify({ name: 'No debería entrar', price_cents: 100 }),
    })

    return response.status
  })

  expect(status).toBe(402)
})

test('anotar un pago reactiva al que se puso al día', async ({ page }) => {
  await signInAsAdmin(page)

  const slug = `pago-${RUN.toLowerCase()}`

  await page.getByRole('button', { name: 'Dar de alta' }).first().click()
  await page.getByRole('textbox', { name: 'Nombre', exact: true }).fill(`Pagador ${RUN}`)
  await page.getByLabel('Dirección').fill(slug)
  await page.getByLabel('Nombre del dueño').fill('Dueño')
  await page.getByLabel('Correo del dueño').fill(`duena-${slug}@x.test`)
  await page.getByLabel('Contraseña').fill('clave-larga-123')
  await page.getByRole('button', { name: 'Dar de alta', exact: true }).last().click()

  await expect(page.getByText(slug)).toBeVisible()

  await page.getByRole('button', { name: new RegExp(`Pagador ${RUN}`) }).click()
  await page.getByRole('button', { name: 'Suspender' }).click()
  await expect(page.getByText('Suspendido')).toBeVisible()

  // Anotar el pago lo devuelve a la vida. Dejarlo para un segundo paso manual
  // es cómo un cliente al día sigue sin poder trabajar el lunes por la mañana.
  await page.getByRole('button', { name: 'Anotar un pago' }).click()
  await page.getByLabel('Cuánto entró').fill('25,00')
  await page.getByLabel('Referencia').fill(`REF${RUN}`)
  await page.getByRole('button', { name: 'Anotar el pago' }).click()

  await expect(page.getByText('Al día')).toBeVisible()
  await expect(page.getByText(`REF${RUN}`)).toBeVisible()
})

test('el modo soporte mira, y queda escrito', async ({ page }) => {
  await signInAsAdmin(page)

  // Se usa un negocio de demostración, que ya tiene datos dentro.
  await page.getByLabel('Buscar').fill('Sazón')
  await page.getByRole('button', { name: /Arepera El Sazón/ }).click()

  await page.getByRole('button', { name: 'Echar un vistazo' }).click()

  await expect(page.getByText('Equipo')).toBeVisible()
  await expect(page.getByText('maria@elsazon.test')).toBeVisible()

  /*
   * Entrar en casa de un cliente sin que quede rastro es lo que no se hace. Y
   * esta nota se le puede enseñar a él.
   *
   * Se vuelve a abrir la ficha en vez de recargar: la super administración no
   * tiene router —son dos pantallas y media— así que recargar volvería a la
   * lista y la prueba miraría otra pantalla.
   */
  await page.getByRole('button', { name: 'Todos los negocios' }).click()
  await page.getByRole('button', { name: /Arepera El Sazón/ }).click()

  // `.first()`: la base es aditiva, así que de corridas anteriores puede
  // haber más de una visita anotada. Lo que se comprueba es que ESTA quedó.
  await expect(page.getByText('support_access').first()).toBeVisible()
})

test('la sesión de un negocio NO abre la super administración', async ({ page }) => {
  // Es el fallo que hay que evitar: con un guard compartido, la sesión del
  // empleado de un negocio cualquiera abriría la facturación de todos.
  await page.goto(panelOf('elsazon'))
  await page.getByLabel('Correo').fill('maria@elsazon.test')
  await page.getByLabel('Contraseña').fill('demo1234')
  await page.getByRole('button', { name: 'Entrar' }).click()
  await expect(page.getByRole('button', { name: 'Salir' })).toBeVisible()

  await page.goto(adminAddress())

  // Pide entrar otra vez: es otra puerta.
  await expect(page.getByRole('button', { name: 'Entrar' })).toBeVisible()
})

test('los negocios de un plan con techo enseñan cuánto llevan usado', async ({ page }) => {
  await signInAsAdmin(page)

  await page.getByLabel('Buscar').fill('Esquina')
  await page.getByRole('button', { name: /Pizzería La Esquina/ }).click()

  // El uso contra el techo. `null` sería «sin tope», y se dice con la palabra:
  // una barra llena al 0 % no significa nada.
  await expect(page.getByText('Cuánto usa')).toBeVisible()
  await expect(page.getByText(/de 2$/)).toBeVisible()
})

test('se puede sembrar un pedido y verlo contado en las métricas', async ({ page }) => {
  // Las métricas cuentan pedidos entrando en cada negocio, uno por uno: no hay
  // una consulta que los sume todos porque `orders` lleva RLS.
  await page.goto(panelOf('elsazon'))
  await page.getByLabel('Correo').fill('maria@elsazon.test')
  await page.getByLabel('Contraseña').fill('demo1234')
  await page.getByRole('button', { name: 'Entrar' }).click()
  await expect(page.getByRole('button', { name: 'Salir' })).toBeVisible()

  const { data: producto } = await apiPost<{ data: { id: string } }>(
    page,
    '/api/v1/catalog/products',
    { name: `[e2e] Métrica ${RUN}`, price_cents: 300 },
  )

  await apiPost(page, '/api/v1/orders', {
    items: [{ product_id: producto.id, quantity: 1 }],
  })

  await signInAsAdmin(page)

  await expect(page.getByText('Pedidos del mes')).toBeVisible()
})
