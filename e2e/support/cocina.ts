import { expect, type Page } from '@playwright/test'
import { cocinaOf, PASSWORD } from './addresses'
import { apiFetch, apiPost } from './api'

/**
 * Entrar a la pantalla de cocina, por sus dos puertas.
 *
 * Se recorren las dos de verdad y no se usa ningún atajo: son exactamente las
 * que se usan en un local, y probar un atajo dejaría sin cubrir lo único que
 * hay que probar.
 */
export async function enterKitchen(
  page: Page,
  tenant: string,
  cocinero: string,
  pin: string,
  altaCon = 'maria@elsazon.test',
): Promise<void> {
  await page.goto(cocinaOf(tenant))

  // Primera puerta: dar de alta la tablet. Sólo la primera vez en su vida,
  // pero cada prueba arranca con un navegador limpio.
  if (await page.getByRole('button', { name: 'Dar de alta' }).isVisible()) {
    await page.getByLabel('Correo').fill(altaCon)
    await page.getByLabel('Contraseña').fill(PASSWORD)
    await page.getByRole('button', { name: 'Dar de alta' }).click()
  }

  // Segunda puerta: quién está en la cocina.
  await expect(page.getByRole('heading', { name: '¿Quién está en la cocina?' })).toBeVisible()
  await page.getByRole('button', { name: cocinero }).click()

  // El PIN se envía solo al cuarto dígito: no hay botón de confirmar, porque
  // un toque de más con las manos ocupadas es un toque de más.
  for (const digit of pin) {
    await page.getByRole('button', { name: digit, exact: true }).click()
  }

  await expect(page.getByRole('heading', { name: 'Cocina' })).toBeVisible()
}

/**
 * La tarjeta de una comanda concreta, por su NOMBRE.
 *
 * Y no por su texto, que en esta pantalla queda pegado al cronómetro
 * —«#13165:00»— y hace que `#131` también encuentre a `#1310`. El nombre
 * accesible de la tarjeta dice exactamente de cuál habla.
 */
export function comanda(page: Page, numero: number) {
  return page.getByRole('article', { name: `Comanda #${numero}`, exact: true })
}

/**
 * Deja el tablero de cocina vacío.
 *
 * La siembra es aditiva y **nadie sirve las comandas de las corridas
 * anteriores**, así que se acumulan para siempre. Pasado el tope de la
 * pantalla, las nuevas dejan de caber —y la pantalla lo dice, que para eso
 * está el aviso— pero la prueba que busca la suya no la encuentra y falla por
 * un motivo que no tiene nada que ver con lo que estaba probando.
 *
 * Se llama con la sesión del PANEL puesta, que es la que tiene permiso para
 * mover comandas.
 */
export async function clearBoard(page: Page): Promise<void> {
  const { data } = await apiFetch<{ data: { id: string; status: string }[] }>(
    page,
    '/api/v1/kitchen/tickets',
  )

  for (const ticket of data) {
    // De donde esté hasta servida: la máquina de estados sólo avanza de uno en
    // uno, y a propósito.
    const pasos = ['preparing', 'ready', 'served'].slice(
      ['pending', 'preparing', 'ready'].indexOf(ticket.status),
    )

    for (const paso of pasos) {
      await apiPost(page, `/api/v1/kitchen/tickets/${ticket.id}/advance`, { status: paso })
    }
  }
}
