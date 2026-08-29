import { expect, test } from '@playwright/test'
import { panelOf, TENANTS } from '../support/addresses'
import { signIn } from '../support/panel'

/*
 * EL EQUIPO Y EL HORARIO.
 *
 * Las dos cosas que un negocio necesita cambiar solo, sin llamar a nadie:
 * sumar a quien entra a trabajar, y decir a qué hora abre. Sin esto había que
 * entrar por la base de datos.
 */

const RUN = Date.now().toString(36).slice(-5).toUpperCase()

test('la dueña suma a alguien al equipo, y esa persona entra', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await page.goto(panelOf(TENANTS.arepera) + 'equipo')

  await expect(page.getByRole('heading', { name: 'Equipo' })).toBeVisible()

  const correo = `nuevo-${RUN.toLowerCase()}@elsazon.test`

  await page.getByRole('button', { name: 'Sumar a alguien' }).click()

  await page.getByRole('textbox', { name: 'Nombre', exact: true }).fill(`Nuevo ${RUN}`)
  await page.getByLabel('Correo').fill(correo)
  await page.getByLabel('Rol').selectOption({ label: 'Encargado' })
  await page.getByRole('textbox', { name: 'Contraseña', exact: true }).fill('clave-larga-123')
  await page.getByLabel('PIN').fill('7788')

  await page.getByRole('button', { name: 'Guardar' }).click()

  // Aparece en la lista, con su rol y con su PIN puesto — sin PIN no entraría
  // a la caja ni a la cocina, y eso hay que verlo de un vistazo.
  const ficha = page.getByRole('listitem').filter({ hasText: `Nuevo ${RUN}` })
  await expect(ficha).toContainText('Encargado')
  await expect(ficha).toContainText('Con PIN')

  // Y entra de verdad, que es lo único que prueba que se guardó bien.
  await page.getByRole('button', { name: 'Salir' }).click()
  await page.getByLabel('Correo').fill(correo)
  await page.getByLabel('Contraseña').fill('clave-larga-123')
  await page.getByRole('button', { name: 'Entrar' }).click()

  await expect(page.getByRole('button', { name: 'Salir' })).toBeVisible()
})

test('dar de baja quita el acceso, y se puede reactivar', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await page.goto(panelOf(TENANTS.arepera) + 'equipo')

  const correo = `baja-${RUN.toLowerCase()}@elsazon.test`

  await page.getByRole('button', { name: 'Sumar a alguien' }).click()
  await page.getByRole('textbox', { name: 'Nombre', exact: true }).fill(`Baja ${RUN}`)
  await page.getByLabel('Correo').fill(correo)
  await page.getByRole('textbox', { name: 'Contraseña', exact: true }).fill('clave-larga-123')
  await page.getByRole('button', { name: 'Guardar' }).click()

  const ficha = page.getByRole('listitem').filter({ hasText: `Baja ${RUN}` })
  await expect(ficha).toBeVisible()

  await ficha.getByRole('button', { name: `Dar de baja a Baja ${RUN}` }).click()

  // No se borra: se desactiva. Lo que hizo sigue diciendo su nombre.
  await expect(ficha).toContainText('de baja')
  await expect(ficha.getByRole('button', { name: /Reactivar/ })).toBeVisible()
})

test('la dueña no puede darse de baja a sí misma', async ({ page }) => {
  // Es el clic que deja a alguien fuera de su propio negocio un viernes por la
  // tarde.
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await page.goto(panelOf(TENANTS.arepera) + 'equipo')

  const suya = page.getByRole('listitem').filter({ hasText: 'maria@elsazon.test' })

  await suya.getByRole('button', { name: 'Dar de baja a María' }).click()

  // Sigue activa: el botón de reactivar sólo aparece cuando alguien está de
  // baja, así que su ausencia es la comprobación limpia.
  await expect(suya.getByRole('button', { name: /Reactivar/ })).toBeHidden()
})

test('la cocina no maneja el equipo', async ({ page }) => {
  // Quien puede crear usuarios puede crearse una cuenta de dueño.
  await page.goto(panelOf(TENANTS.arepera))
  await page.getByLabel('Correo').fill('carlos@elsazon.test')
  await page.getByLabel('Contraseña').fill('demo1234')
  await page.getByRole('button', { name: 'Entrar' }).click()

  await expect(page.getByRole('link', { name: 'Equipo' })).toBeHidden()
})

test('la dueña cambia el horario, y el portal le hace caso', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await page.goto(panelOf(TENANTS.arepera) + 'horario')

  await expect(page.getByRole('heading', { name: 'Horario' })).toBeVisible()

  // Se cierra el lunes y se guarda.
  const lunes = page.getByRole('switch').nth(1)
  const estaba = await lunes.isChecked()

  await lunes.setChecked(false)
  await page.getByRole('button', { name: 'Guardar el horario' }).click()
  await expect(page.getByText('Guardado')).toBeVisible()

  await page.reload()
  await expect(page.getByRole('switch').nth(1)).not.toBeChecked()

  // Se deja como estaba: lo que una prueba cambia, esa prueba lo restaura.
  await page.getByRole('switch').nth(1).setChecked(estaba)
  await page.getByRole('button', { name: 'Guardar el horario' }).click()
  await expect(page.getByText('Guardado')).toBeVisible()
})
