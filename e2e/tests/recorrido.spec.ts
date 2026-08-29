import { expect, test, type Page } from '@playwright/test'
import { addressOf, adminAddress, panelOf } from '../support/addresses'
import { apiFetch } from '../support/api'
import { enterRegister, ticket } from '../support/caja'
import { comanda, enterKitchen } from '../support/cocina'
import { signIn, signOut } from '../support/panel'
import { addToCart, cartBar, openMenu } from '../support/portal'

/*
 * DE CERO A UN NEGOCIO TRABAJANDO.
 *
 * Todo lo demás en esta carpeta prueba una pantalla sobre los negocios ya
 * sembrados. Esto prueba lo otro: **un negocio que no existía**, dado de alta
 * desde la super administración, cargado a mano por su dueña, y llevado hasta
 * cobrar por las dos puertas —el portal y el mostrador— con la cocina y el
 * reparto por medio.
 *
 * Es el recorrido que hace un cliente nuevo el primer día, y el único que
 * atrapa una clase entera de fallos: los que sólo aparecen cuando NO hay datos
 * de antes. Una carta vacía, un negocio sin zonas, un equipo de una sola
 * persona, un correlativo de notas que arranca en cero.
 *
 * ── En serie, y a propósito ────────────────────────────────────────────────
 *
 * Cada prueba es un paso del primer día y se apoya en el anterior. Si el alta
 * falla, las once siguientes no tienen nada que decir: `serial` las salta en
 * vez de soltar doce fallos que cuentan la misma noticia una y otra vez.
 */

test.describe.configure({ mode: 'serial' })

const RUN = Date.now().toString(36).slice(-5).toLowerCase()

/** El negocio de esta corrida. La siembra es aditiva: cada corrida crea el suyo. */
const NEGOCIO = {
  slug: `cero-${RUN}`,
  nombre: `Arepera Cero ${RUN.toUpperCase()}`,
}

const DUENA = { nombre: 'Rosa', correo: `rosa-${RUN}@cero.test`, clave: 'clave-larga-123' }

/** El equipo que se arma en el paso 2, y que usan los pasos siguientes. */
const EQUIPO = {
  caja: { nombre: `Ana ${RUN}`, correo: `ana-${RUN}@cero.test`, rol: 'Mostrador', pin: '3456' },
  cocina: { nombre: `Beto ${RUN}`, correo: `beto-${RUN}@cero.test`, rol: 'Cocina', pin: '4567' },
  reparto: { nombre: `Luis ${RUN}`, correo: `luis-${RUN}@cero.test`, rol: 'Repartidor', pin: '' },
}

const CATEGORIA = `Arepas ${RUN}`
const AGREGADOS = `¿Con qué la quieres? ${RUN}`
const AREPA = `Reina pepiada ${RUN}`
const REFRESCO = `Refresco ${RUN}`
const ZONA = `Los Palos Grandes ${RUN}`

/** El pedido del portal, que van a tocar la cocina y el reparto. */
let pedidoDelPortal = { numero: 0, cliente: '' }

async function entrarComoDuena(page: Page): Promise<void> {
  await signIn(page, NEGOCIO.slug, DUENA.correo, DUENA.clave)
}

/**
 * Cambiar de persona en la misma pantalla.
 *
 * Vuelve a la RAÍZ del panel antes de entrar, y no es un adorno: al salir, la
 * dirección se queda donde estaba. Si la anterior era `/equipo` y quien entra
 * ahora es el repartidor —que no tiene esa pantalla— aterriza en «esa pantalla
 * no existe» en vez de en su trabajo.
 */
async function cambiarDePersona(page: Page, correo: string): Promise<void> {
  await signOut(page)
  await page.goto(panelOf(NEGOCIO.slug))

  await page.getByLabel('Correo').fill(correo)
  await page.getByLabel('Contraseña').fill(DUENA.clave)
  await page.getByRole('button', { name: 'Entrar' }).click()
}

/** Una pantalla del panel, por su ruta. */
async function irA(page: Page, ruta: string): Promise<void> {
  await page.goto(panelOf(NEGOCIO.slug) + ruta)
}

// ─────────────────────────────────────────────────────────────────────────────

test('1 · se da de alta el negocio desde la super administración', async ({ page }) => {
  await page.goto(adminAddress())
  await page.getByLabel('Correo').fill('admin@kombo.test')
  await page.getByLabel('Contraseña').fill('demo1234')
  await page.getByRole('button', { name: 'Entrar' }).click()
  await expect(page.getByRole('button', { name: 'Salir' })).toBeVisible()

  await page.getByRole('button', { name: 'Dar de alta' }).first().click()

  await page.getByRole('textbox', { name: 'Nombre', exact: true }).fill(NEGOCIO.nombre)
  await page.getByLabel('Dirección').fill(NEGOCIO.slug)
  // El plan completo: es el que trae caja, notas, reparto y reportes, o sea
  // todo lo que este recorrido va a tocar.
  await page.getByLabel('Plan').selectOption('completo')
  await page.getByLabel('Nombre del dueño').fill(DUENA.nombre)
  await page.getByLabel('Correo del dueño').fill(DUENA.correo)
  await page.getByLabel('Contraseña').fill(DUENA.clave)

  await page.getByRole('button', { name: 'Dar de alta', exact: true }).last().click()

  await expect(page.getByText(NEGOCIO.slug)).toBeVisible()

  // Y lo único que prueba que el alta sirvió: la dueña entra a su negocio.
  await entrarComoDuena(page)

  // Su portal ya está en pie, con su nombre y todavía sin carta.
  await page.goto(addressOf(NEGOCIO.slug, '/'))
  await expect(page.getByRole('heading', { name: NEGOCIO.nombre })).toBeVisible()
})

test('2 · la dueña arma su equipo: mostrador, cocina y reparto', async ({ page }) => {
  await entrarComoDuena(page)
  await irA(page, 'equipo')

  await expect(page.getByRole('heading', { name: 'Equipo' })).toBeVisible()

  // Arranca sola: el alta crea a la dueña y a nadie más.
  await expect(page.getByRole('listitem').filter({ hasText: DUENA.correo })).toBeVisible()

  for (const persona of Object.values(EQUIPO)) {
    await page.getByRole('button', { name: 'Sumar a alguien' }).click()

    await page.getByRole('textbox', { name: 'Nombre', exact: true }).fill(persona.nombre)
    await page.getByLabel('Correo').fill(persona.correo)
    await page.getByLabel('Rol').selectOption({ label: persona.rol })
    await page.getByRole('textbox', { name: 'Contraseña', exact: true }).fill(DUENA.clave)

    if (persona.pin !== '') {
      await page.getByLabel('PIN').fill(persona.pin)
    }

    await page.getByRole('button', { name: 'Guardar' }).click()

    const ficha = page.getByRole('listitem').filter({ hasText: persona.nombre })
    await expect(ficha).toContainText(persona.rol)

    // El PIN se ve de un vistazo, y hace falta: sin él no se entra ni a la caja
    // ni a la cocina, y descubrirlo con un cliente delante es tarde.
    if (persona.pin !== '') {
      await expect(ficha).toContainText('Con PIN')
    }
  }

  // Y el reparto entra de verdad, con su propio rol: aterriza en Entregas y no
  // ve nada más. Es lo que separa «se creó el usuario» de «esa persona trabaja».
  await cambiarDePersona(page, EQUIPO.reparto.correo)

  await expect(page.getByRole('heading', { name: 'Entregas' })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Carta' })).toBeHidden()
  await expect(page.getByRole('link', { name: 'Equipo' })).toBeHidden()
})

test('3 · pone el horario, y el portal deja de estar cerrado', async ({ page }) => {
  await entrarComoDuena(page)
  await irA(page, 'horario')

  await expect(page.getByRole('heading', { name: 'Horario' })).toBeVisible()

  /*
   * De 00:00 a 23:59 todos los días.
   *
   * No es capricho de horario: el alta deja 08:00–20:00, así que una suite
   * corrida a las once de la noche encontraría el negocio cerrado y fallaría
   * por el reloj y no por el código. Una prueba que sólo pasa de día es una
   * prueba intermitente con calendario.
   */
  for (const abre of await page.getByLabel(/^Abre el /).all()) {
    await abre.fill('00:00')
  }

  for (const cierra of await page.getByLabel(/^Cierra el /).all()) {
    await cierra.fill('23:59')
  }

  await page.getByRole('button', { name: 'Guardar el horario' }).click()
  await expect(page.getByText('Guardado')).toBeVisible()

  // El portal lo dice desde fuera, que es donde importa.
  await signOut(page)
  await openMenu(page, NEGOCIO.slug)
  await expect(page.getByText('Abierto ahora')).toBeVisible()
})

test('4 · carga la tasa del día', async ({ page }) => {
  await entrarComoDuena(page)

  // La carta avisa antes de que sea un problema: sin tasa no se cobra en
  // bolívares, y eso se descubre con un cliente delante.
  await irA(page, 'carta')
  await expect(page.getByRole('alert')).toContainText('tasa del día')

  await irA(page, 'tasa')
  await page.getByLabel('Bolívares por dólar').fill('40,00')
  await page.getByRole('button', { name: 'Guardar la tasa' }).click()

  await expect(page.getByText('Bs 40 por dólar')).toBeVisible()

  // Y con un importe de verdad: 100 $ a 40 son Bs 4.000,00. Un «40» suelto no
  // delata un cero de más; esto sí.
  await expect(page.getByText('Bs 4.000,00')).toBeVisible()

  // El aviso de la carta se fue.
  await irA(page, 'carta')
  await expect(page.getByRole('alert')).toBeHidden()
})

test('5 · carga la carta: categoría, agregados y dos productos', async ({ page }) => {
  await entrarComoDuena(page)

  // ── La categoría ──
  await irA(page, 'categorias')
  await page.getByLabel('Nueva categoría').fill(CATEGORIA)
  await page.getByRole('button', { name: 'Añadir' }).click()
  await expect(page.getByRole('listitem').filter({ hasText: CATEGORIA })).toBeVisible()

  // ── Los agregados ──
  await irA(page, 'agregados')
  await page.getByLabel('La pregunta').fill(AGREGADOS)
  await page.getByLabel('Opción 1', { exact: true }).fill('Sin cebolla')
  await page.getByRole('button', { name: 'Otra opción' }).click()
  await page.getByLabel('Opción 2', { exact: true }).fill('Extra queso')
  await page.getByLabel('Precio de la opción 2').fill('0,50')
  await page.getByRole('button', { name: 'Guardar el grupo' }).click()

  const grupo = page.getByRole('listitem').filter({ hasText: AGREGADOS })
  await expect(grupo.getByText('Extra queso $0,50')).toBeVisible()

  // ── La carta vacía dice qué hacer, no sólo que está vacía ──
  await irA(page, 'carta')
  await expect(page.getByRole('link', { name: /Añadir el primero/i })).toBeVisible()

  // ── El primer producto, con categoría y agregados ──
  await irA(page, 'carta/nuevo')
  await page.getByLabel('Nombre').fill(AREPA)
  await page.getByLabel('Precio en dólares').fill('3,50')
  await page.getByLabel('Categoría').selectOption({ label: CATEGORIA })
  await page.getByLabel('Descripción').fill('Pollo y aguacate.')
  await page.getByLabel('Minutos que tarda').fill('8')
  // `getByLabel` y no `getByRole(..., {name})`: el nombre accesible de la
  // casilla es «{grupo} — {regla}», así que un nombre exacto no encaja. Y con
  // una expresión regular tampoco: el nombre del grupo lleva un «?» dentro,
  // que en una expresión regular no es un signo de interrogación sino un
  // cuantificador — y deja de coincidir justo donde parece que debería.
  await page.getByLabel(AGREGADOS).check()
  await page.getByRole('button', { name: 'Guardar' }).click()

  // ── El segundo, para que el pedido lleve dos líneas ──
  await irA(page, 'carta/nuevo')
  await page.getByLabel('Nombre').fill(REFRESCO)
  await page.getByLabel('Precio en dólares').fill('1,00')
  await page.getByRole('button', { name: 'Guardar' }).click()

  await page.getByRole('searchbox', { name: 'Buscar en la carta' }).fill(AREPA)
  const enLaCarta = page.getByRole('listitem').filter({ hasText: AREPA })
  await expect(enLaCarta).toContainText('$3,50')

  /*
   * Y el precio guardado EN CENTAVOS, no en coma flotante.
   *
   * Que la pantalla diga «$3,50» no prueba nada: 3.5 en coma flotante acaba en
   * un cuadre que no cierra tres meses después, y para entonces nadie sabe de
   * dónde salió.
   */
  const { data } = await apiFetch<{ data: Array<{ name: string; priceCents: number }> }>(
    page,
    '/api/v1/catalog/products',
  )

  expect(data.find((p) => p.name === AREPA)?.priceCents).toBe(350)
})

test('6 · le pone la foto al producto, que es lo que vende en el portal', async ({ page }) => {
  await entrarComoDuena(page)
  await irA(page, 'carta')

  // Se abre para editar: la foto se cuelga de un producto que ya existe.
  await page.getByRole('link', { name: new RegExp(AREPA) }).click()

  await expect(page.getByLabel('Nombre')).toHaveValue(AREPA)

  await page.getByLabel('Foto').setInputFiles({
    name: 'arepa.png',
    mimeType: 'image/png',
    // Un PNG de un píxel. La regla `image` del servidor valida el CONTENIDO,
    // no la extensión, así que tiene que ser una imagen de verdad.
    buffer: Buffer.from(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
      'base64',
    ),
  })

  // Aparece el botón de quitarla: es lo que sólo puede pasar si la subida
  // terminó bien.
  await expect(page.getByRole('button', { name: 'Quitar' })).toBeVisible()

  const { data } = await apiFetch<{ data: Array<{ name: string; photoUrl: string | null }> }>(
    page,
    '/api/v1/catalog/products',
  )

  expect(data.find((p) => p.name === AREPA)?.photoUrl).toContain('/storage/products/')
})

test('7 · dibuja las zonas a las que reparte', async ({ page }) => {
  await entrarComoDuena(page)
  await irA(page, 'zonas')

  await expect(page.getByText('Todavía no repartes a ningún sitio')).toBeVisible()

  await page.getByLabel('Zona').fill(ZONA)
  await page.getByLabel('Se cobra').fill('2,00')
  await page.getByLabel('Minutos').fill('30')
  await page.getByRole('button', { name: 'Añadir' }).click()

  const zona = page.getByRole('listitem').filter({ hasText: ZONA })
  await expect(zona).toContainText('30 min')
  await expect(zona).toContainText('$2,00')
})

test('8 · un cliente pide a domicilio desde el portal, sin cuenta', async ({ page }) => {
  // Aquí no hay sesión de nadie: es alguien de la calle que abrió el enlace.
  await openMenu(page, NEGOCIO.slug)

  await expect(page.getByRole('heading', { name: NEGOCIO.nombre })).toBeVisible()
  await expect(page.getByText(CATEGORIA)).toBeVisible()

  // La arepa con su agregado de pago, y un refresco.
  await page.getByRole('button', { name: new RegExp(AREPA) }).click()
  const hoja = page.getByRole('dialog', { name: new RegExp(AREPA) })
  await hoja.getByLabel('Extra queso').check()
  await hoja.getByRole('button', { name: /Agregar/ }).click()
  await expect(hoja).toBeHidden()

  await addToCart(page, REFRESCO)

  // 3,50 + 0,50 del queso + 1,00 del refresco.
  await expect(cartBar(page)).toContainText('$5,00')
  await cartBar(page).click()

  await expect(page.getByRole('heading', { name: 'Tu pedido' })).toBeVisible()

  await page.getByRole('button', { name: 'Me lo traen' }).click()

  const zona = page.getByLabel('¿A qué zona?')
  const valor = await zona.locator('option').filter({ hasText: ZONA }).getAttribute('value')
  await zona.selectOption(valor ?? '')

  // La tarifa va EN la opción: se elige sabiendo cuánto cuesta y cuánto tarda.
  await expect(zona).toContainText('$2,00')

  pedidoDelPortal.cliente = `Cliente ${RUN.toUpperCase()}`

  await page.getByLabel('Dirección').fill(`Cuarta avenida, casa ${RUN}`)
  await page.getByLabel('¿Cómo te llamas?').fill(pedidoDelPortal.cliente)
  await page.getByLabel('Teléfono').fill(`0414${Date.now().toString().slice(-7)}`)
  await page.getByRole('button', { name: 'Efectivo al recibir' }).click()

  // 5,00 del pedido + 2,00 del reparto. El total lo dice el botón.
  const hacerPedido = page.getByRole('button', { name: /Hacer el pedido/ })
  await expect(hacerPedido).toContainText('$7,00')
  await hacerPedido.click()

  // Se acaba en el seguimiento, y el enlace queda en la barra de direcciones:
  // es lo que le permite volver mañana a ver qué pasó.
  await expect(page).toHaveURL(/\/p\/[A-Za-z0-9]+$/)
  await expect(page.getByText('Recibido, ya lo vemos')).toBeVisible()

  const encabezado = await page.getByRole('heading', { level: 1 }).innerText()
  const numero = /Pedido #(\d+)/.exec(encabezado)

  expect(numero).not.toBeNull()
  pedidoDelPortal.numero = Number(numero![1])

  // El primer pedido de un negocio nuevo lleva el número 1. Es la comprobación
  // de que el correlativo arranca por negocio y no compartido con nadie.
  expect(pedidoDelPortal.numero).toBe(1)
})

test('9 · la dueña lo confirma y la comanda cae sola en la cocina', async ({ page }) => {
  await entrarComoDuena(page)
  await irA(page, 'pedidos')

  // Por el nombre del cliente: el número queda pegado a la hora en el texto de
  // la fila, así que «#1» encontraría también a «#12».
  const fila = page.getByRole('listitem').filter({ hasText: pedidoDelPortal.cliente })

  await expect(fila).toContainText(`#${pedidoDelPortal.numero}`)
  await expect(fila).toContainText(AREPA)

  await fila.getByRole('button', { name: 'Confirmar' }).click()

  // Y ahora la cocina, por su propia puerta: la tablet se da de alta con la
  // clave de la dueña y después se entra con el PIN del cocinero.
  await signOut(page)
  await page.evaluate(() => localStorage.clear())

  await enterKitchen(
    page,
    NEGOCIO.slug,
    EQUIPO.cocina.nombre,
    EQUIPO.cocina.pin,
    DUENA.correo,
    DUENA.clave,
  )

  const tarjeta = comanda(page, pedidoDelPortal.numero)

  await expect(tarjeta).toBeVisible()
  await expect(tarjeta).toContainText(AREPA)
  // El agregado, en su línea y en ámbar: es justo lo que se pasa por alto al
  // leer rápido, y pasarlo por alto es rehacer el plato.
  await expect(tarjeta).toContainText('Extra queso')
  await expect(tarjeta).toContainText('Delivery')

  // El cocinero la empieza y la termina.
  await tarjeta.getByRole('button', { name: 'Empezar' }).click()
  await expect(tarjeta.getByRole('button', { name: 'Listo' })).toBeVisible()

  await tarjeta.getByRole('button', { name: 'Listo' }).click()
  await expect(tarjeta.getByRole('button', { name: 'Entregado' })).toBeVisible()
})

test('10 · el repartidor la toma, sale y la entrega', async ({ page }) => {
  /*
   * El pedido lo mueve el panel, no la cocina.
   *
   * La comanda y el pedido son dos cosas: la pantalla de cocina lleva la
   * comida, el tablero de pedidos lleva el pedido. Marcar «Listo» en la cocina
   * NO pone el pedido en «listo», así que quien atiende tiene que moverlo — y
   * hasta que no lo haga, el pedido no aparece en la pantalla del repartidor.
   */
  await entrarComoDuena(page)
  await irA(page, 'pedidos')

  const fila = page.getByRole('listitem').filter({ hasText: pedidoDelPortal.cliente })

  await fila.getByRole('button', { name: 'A la cocina' }).click()
  await fila.getByRole('button', { name: 'Listo' }).click()

  // Y ahora sí: el repartidor, con su cuenta y su rol.
  await cambiarDePersona(page, EQUIPO.reparto.correo)

  await expect(page.getByRole('heading', { name: 'Entregas' })).toBeVisible()

  const tarjeta = page.getByText(`Cuarta avenida, casa ${RUN}`).locator('../..')

  // Lo que hay que cobrar al llegar: 5,00 del pedido más 2,00 del reparto. Es
  // lo único que el repartidor necesita saber del dinero.
  await expect(tarjeta).toContainText('Cobrar')
  await expect(tarjeta).toContainText('$7,00')

  await tarjeta.getByRole('button', { name: 'Lo llevo yo' }).click()
  await page.getByRole('button', { name: 'Salgo con él' }).click()
  await page.getByRole('button', { name: 'Entregado' }).click()

  // Sale de la lista: ya no es asunto de nadie.
  await expect(page.getByText(`Cuarta avenida, casa ${RUN}`)).toBeHidden()
})

test('11 · una venta de mostrador sale con su nota de entrega', async ({ page }) => {
  await enterRegister(
    page,
    NEGOCIO.slug,
    EQUIPO.caja.nombre,
    EQUIPO.caja.pin,
    DUENA.correo,
    DUENA.clave,
  )

  // La arepa lleva una pregunta obligatoria… no: es opcional, así que la hoja
  // deja agregar sin contestar. Se contesta igual, que es lo que hace alguien
  // en el mostrador.
  await page.getByRole('button', { name: new RegExp(AREPA) }).click()

  const hoja = page.getByRole('dialog', { name: new RegExp(AREPA) })
  await hoja.getByLabel('Sin cebolla').check()
  await hoja.getByRole('button', { name: /Agregar/ }).click()

  // El refresco no pregunta nada, así que entra a la cuenta de un toque: en el
  // mostrador, una hoja que sólo dice «agregar» es un toque de más con un
  // cliente delante.
  await page.getByRole('button', { name: new RegExp(REFRESCO) }).click()

  await expect(ticket(page)).toContainText(AREPA)
  await expect(ticket(page)).toContainText(REFRESCO)
  await expect(ticket(page)).toContainText('$4,50')

  await page.getByRole('button', { name: 'Cobrar', exact: true }).click()

  const cobro = page.getByRole('dialog', { name: 'Cobrar' })

  // Mezclado, que es como se cobra de verdad: dos dólares en efectivo…
  await cobro.getByLabel('Monto').fill('2,00')
  await cobro.getByRole('button', { name: 'Agregar este pago' }).click()

  // …y la pantalla dice cuánto falta, sin restar de cabeza.
  await expect(cobro).toContainText('$2,50')

  // …el resto por pago móvil, con su referencia.
  await cobro.getByRole('button', { name: 'Pago móvil' }).click()
  await cobro.getByLabel('Referencia').fill(`99${RUN}`)
  await cobro.getByRole('button', { name: 'Agregar este pago' }).click()

  await cobro.getByRole('button', { name: 'Cobrar $4,50' }).click()

  const nota = page.getByRole('dialog')

  // El papel dice lo que es, y las dos frases vienen del servidor: están
  // guardadas dentro del propio documento, no las pone la pantalla.
  await expect(nota).toContainText('NOTA DE ENTREGA')
  await expect(nota).toContainText('No es una factura')
  await expect(nota).not.toContainText('FACTURA', { ignoreCase: false })

  await expect(nota).toContainText(`Pago móvil · 99${RUN}`)
  await expect(nota).toContainText('$4,50')

  // El correlativo de un negocio nuevo arranca en su primera nota. Es lo que
  // prueba que la serie es POR NEGOCIO y no una sola compartida.
  expect(await nota.getAttribute('aria-label')).toBe('Nota A-000001')
})

test('12 · la libreta de clientes se llenó sola', async ({ page }) => {
  await entrarComoDuena(page)
  await irA(page, 'clientes')

  // Nadie la escribió: la ficha salió del pedido del portal.
  const ficha = page.getByRole('listitem').filter({ hasText: pedidoDelPortal.cliente })

  await expect(ficha).toContainText('1 pedido')

  // La nota es lo único que se escribe a mano, y lo que hace que la libreta
  // sirva para algo.
  await ficha.getByRole('button', { name: pedidoDelPortal.cliente }).click()
  await page.getByLabel('Nota').fill('Toca el timbre dos veces')
  await page.getByRole('button', { name: 'Guardar la nota' }).click()

  await page.reload()
  await page.getByRole('button', { name: pedidoDelPortal.cliente }).click()
  await expect(page.getByLabel('Nota')).toHaveValue('Toca el timbre dos veces')
})

test('13 · las ventas del día cuadran con lo que se cobró', async ({ page }) => {
  await entrarComoDuena(page)
  await irA(page, 'ventas')

  await expect(page.getByRole('heading', { name: 'Ventas' })).toBeVisible()

  /*
   * Dos ventas y $11,50.
   *
   * 7,00 del pedido del portal —5,00 de comida más 2,00 de reparto— y 4,50 del
   * mostrador. Se afirma la SUMA y no «hay ventas»: un reporte que cuenta dos
   * pedidos pero suma mal es exactamente el que nadie descubre hasta que el
   * dueño cuadra la caja a mano y no le da.
   */
  await expect(page.getByText('2 pedidos')).toBeVisible()
  await expect(page.getByText('$11,50')).toBeVisible()

  // Y se lo puede llevar a una hoja de cálculo.
  const descarga = page.waitForEvent('download')
  await page.getByRole('link', { name: 'Exportar' }).click()

  expect((await descarga).suggestedFilename()).toContain(`pedidos-${NEGOCIO.slug}`)
})
