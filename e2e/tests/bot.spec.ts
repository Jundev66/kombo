import { expect, test, type Page } from '@playwright/test'
import { panelOf, TENANTS } from '../support/addresses'
import { apiFetch, apiPost } from '../support/api'
import { signIn } from '../support/panel'

/*
 * EL BOT, DE PUNTA A PUNTA.
 *
 * El dueño conecta WhatsApp desde el panel, llega un mensaje firmado como lo
 * firmaría Meta, y el bot contesta con su carta. Nada de eso está simulado por
 * dentro: se recorre el webhook de verdad, con su firma de verdad, contra el
 * negocio que resuelve la tabla de rutas.
 *
 * El webhook se manda **desde la página**, como todas las llamadas de estas
 * pruebas: `page.request` resuelve nombres con Node y no ve el comodín de
 * subdominios del contenedor.
 *
 * Y se ESPERA la respuesta: el mensaje se procesa en la cola —Meta corta a los
 * 30 segundos y por eso el webhook contesta 200 en el acto—, así que la
 * respuesta del bot llega un momento después. Es lo que pasa en producción.
 */

const RUN = Date.now().toString(36).slice(-5).toUpperCase()

/** Un número de WhatsApp de mentira, distinto en cada corrida. */
const NUMERO = `5551${Date.now().toString().slice(-8)}`

const SECRETO = `secreto-de-prueba-${RUN}`

/**
 * Manda un webhook FIRMADO como lo firma Meta: HMAC-SHA256 del cuerpo crudo.
 *
 * La firma se calcula dentro de la página con Web Crypto, sobre exactamente el
 * mismo texto que se manda. Un espacio de diferencia y no cuadra — que es justo
 * lo que esta prueba tiene que ejercitar.
 */
async function sendWebhook(
  page: Page,
  body: unknown,
  options: { sign?: boolean } = {},
): Promise<number> {
  return page.evaluate(
    async ({ cuerpo, secreto, firmar }: { cuerpo: unknown; secreto: string; firmar: boolean }) => {
      const json = JSON.stringify(cuerpo)

      const headers: Record<string, string> = {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      }

      if (firmar) {
        const key = await crypto.subtle.importKey(
          'raw',
          new TextEncoder().encode(secreto),
          { name: 'HMAC', hash: 'SHA-256' },
          false,
          ['sign'],
        )

        const mac = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(json))

        headers['X-Hub-Signature-256'] =
          'sha256=' +
          Array.from(new Uint8Array(mac))
            .map((b) => b.toString(16).padStart(2, '0'))
            .join('')
      }

      const response = await fetch('/webhooks/whatsapp', {
        method: 'POST',
        headers,
        body: json,
      })

      return response.status
    },
    { cuerpo: body, secreto: SECRETO, firmar: options.sign !== false },
  )
}

/** El cuerpo que manda Meta cuando alguien escribe o toca un botón. */
function whatsAppBody(id: string, options: { text?: string; button?: string } = {}) {
  const message: Record<string, unknown> = {
    id,
    from: `58414${RUN.replace(/\D/g, '') || '0'}0000`.slice(0, 12),
    timestamp: '1',
    type: options.button != null ? 'interactive' : 'text',
  }

  if (options.button != null) {
    message['interactive'] = {
      type: 'button_reply',
      button_reply: { id: options.button, title: 'x' },
    }
  } else {
    message['text'] = { body: options.text ?? 'hola' }
  }

  return {
    object: 'whatsapp_business_account',
    entry: [
      {
        id: '1',
        changes: [
          {
            field: 'messages',
            value: {
              messaging_product: 'whatsapp',
              metadata: { phone_number_id: NUMERO },
              contacts: [{ wa_id: message['from'], profile: { name: `Cliente ${RUN}` } }],
              messages: [message],
            },
          },
        ],
      },
    ],
  }
}

interface Conversation {
  id: string
  customerName: string | null
}

interface Message {
  direction: string
  content: string
}

/**
 * Espera a que el bot CONTESTE lo que se espera.
 *
 * Se espera a la respuesta y no a que exista la conversación, y la diferencia
 * es una prueba intermitente: el trabajo crea la conversación, guarda lo que
 * entró y sólo entonces contesta. Esperar a la conversación deja pasar unos
 * milisegundos en los que la respuesta todavía no está escrita.
 */
async function waitForReply(page: Page, contiene: string): Promise<Message[]> {
  let messages: Message[] = []

  await expect
    .poll(
      async () => {
        const { data } = await apiFetch<{ data: Conversation[] }>(page, '/api/v1/conversations')

        const conversation = data.find((c) => c.customerName === `Cliente ${RUN}`)

        if (conversation === undefined) return false

        const detalle = await apiFetch<{ data: { messages: Message[] } }>(
          page,
          `/api/v1/conversations/${conversation.id}`,
        )

        messages = detalle.data.messages

        return messages.some((m) => m.direction === 'out' && m.content.includes(contiene))
      },
      { timeout: 20_000, message: 'El bot no contestó' },
    )
    .toBe(true)

  return messages
}

/**
 * Deja WhatsApp conectado con el número y el secreto de esta corrida.
 *
 * Cada prueba arranca con un navegador limpio, pero la base NO se rehace: sin
 * esto, la segunda prueba mandaría un webhook firmado con un secreto que ya no
 * es el que está guardado.
 */
async function connectWhatsApp(page: Page): Promise<void> {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await page.goto(panelOf(TENANTS.arepera) + 'canales')

  const whatsapp = page.getByRole('region', { name: 'WhatsApp' })

  await whatsapp.getByRole('button', { name: /Conectar|Cambiar el token/ }).click()
  await whatsapp.getByLabel('Identificador del número').fill(NUMERO)
  await whatsapp.getByLabel('Token permanente').fill(`token-de-prueba-${RUN}`)
  await whatsapp.getByLabel('Secreto del webhook').fill(SECRETO)
  await whatsapp.getByRole('button', { name: 'Guardar', exact: true }).click()

  /*
   * Se espera a que el formulario SE CIERRE, no a que el canal diga
   * «Conectado».
   *
   * La diferencia importa: la base no se rehace entre corridas, así que el
   * canal ya podía estar conectado de antes y ese texto ya estaba ahí. La
   * aserción pasaría al instante, el webhook saldría antes de que se guardara
   * el número nuevo, y el fallo aparecería tres pruebas después.
   */
  await expect(whatsapp.getByRole('button', { name: 'Guardar', exact: true })).toBeHidden()
}

test('el dueño conecta WhatsApp y el bot empieza a contestar', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')

  await page.goto(panelOf(TENANTS.arepera) + 'canales')
  await expect(page.getByRole('heading', { name: 'WhatsApp y Telegram' })).toBeVisible()

  // La tarjeta del canal, por su nombre. La siembra es aditiva y una corrida
  // anterior pudo dejar WhatsApp ya conectado, así que se toma el botón que
  // haya —«Conectar» o «Cambiar el token»— en vez de suponer cuál es.
  const whatsapp = page.getByRole('region', { name: 'WhatsApp' })

  await whatsapp.getByRole('button', { name: /Conectar|Cambiar el token/ }).click()

  await whatsapp.getByLabel('Identificador del número').fill(NUMERO)
  await whatsapp.getByLabel('Token permanente').fill(`token-de-prueba-${RUN}`)
  await whatsapp.getByLabel('Secreto del webhook').fill(SECRETO)
  await whatsapp.getByRole('button', { name: 'Guardar', exact: true }).click()

  // Se espera a que el formulario se cierre —eso sólo pasa si se guardó— y
  // después a lo que se ve: conectado, y con la dirección que hay que pegar en
  // la consola de Meta ya armada. Escribirla a mano y equivocarse en un
  // carácter es una tarde perdida.
  await expect(whatsapp.getByRole('button', { name: 'Guardar', exact: true })).toBeHidden()
  await expect(whatsapp.getByText('Conectado')).toBeVisible()
  await expect(whatsapp.getByText('/webhooks/whatsapp')).toBeVisible()

  // ── Llega un mensaje firmado, como lo mandaría Meta.
  expect(await sendWebhook(page, whatsAppBody(`wamid.${RUN}.1`))).toBe(200)

  // El bot contestó con su menú, y quedó guardado lo que dijo cada uno: media
  // conversación no sirve para nada cuando el encargado la abre.
  const messages = await waitForReply(page, '¿Qué quieres hacer?')

  expect(messages.some((m) => m.direction === 'in')).toBe(true)
})

test('un webhook sin firma no entra', async ({ page }) => {
  // Cualquiera en internet puede hacer un POST a esta dirección. Lo único que
  // separa un mensaje de Meta de uno inventado es la firma.
  await connectWhatsApp(page)

  const status = await sendWebhook(page, whatsAppBody(`wamid.${RUN}.sinfirma`), { sign: false })

  expect(status).toBe(403)
})

test('el bot enseña la carta y manda al portal a pedir', async ({ page }) => {
  await connectWhatsApp(page)

  // Se siembra la sección Y su producto: el bot enseña categorías sólo si hay,
  // y sin esto la prueba dependería de lo que dejaran otras corridas.
  const { data: categoria } = await apiPost<{ data: { id: string } }>(
    page,
    '/api/v1/catalog/categories',
    { name: `[e2e] Bot ${RUN}` },
  )

  await apiPost(page, '/api/v1/catalog/products', {
    name: `[e2e] Arepa bot ${RUN}`,
    price_cents: 300,
    category_id: categoria.id,
  })

  expect(await sendWebhook(page, whatsAppBody(`wamid.${RUN}.2`, { button: 'carta' }))).toBe(200)

  // La carta, con sus secciones.
  await waitForReply(page, '¿Qué te provoca?')
})
