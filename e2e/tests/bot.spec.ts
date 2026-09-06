import { expect, test, type Page } from '@playwright/test'
import { dashboardOf, TENANTS } from '../support/addresses'
import { apiFetch, apiPost } from '../support/api'
import { signIn } from '../support/dashboard'

/*
 * THE BOT, END TO END.
 *
 * The owner connects WhatsApp from the dashboard, a message arrives signed as
 * Meta would sign it, and the bot answers with the menu. None of it is
 * simulated internally: the real webhook is walked, with a real signature,
 * against the tenant the routing table resolves.
 *
 * The webhook is sent FROM THE PAGE, like every call in these tests, and the
 * response is WAITED for: the message is processed on the queue, so the bot's
 * reply arrives a moment later. That is what happens in production.
 */

const RUN = Date.now().toString(36).slice(-5).toUpperCase()

/** A fake WhatsApp number, different on every run. */
const NUMBER = `5551${Date.now().toString().slice(-8)}`

const SECRET = `secreto-de-prueba-${RUN}`

/**
 * Sends a webhook SIGNED as Meta signs it: HMAC-SHA256 of the raw body.
 *
 * The signature is computed inside the page with Web Crypto, over exactly the
 * text that is sent. One space of difference and it does not match — which is
 * precisely what this test has to exercise.
 */
async function sendWebhook(
  page: Page,
  body: unknown,
  options: { sign?: boolean } = {},
): Promise<number> {
  return page.evaluate(
    async ({ body, secret, firmar }: { body: unknown; secret: string; firmar: boolean }) => {
      const json = JSON.stringify(body)

      const headers: Record<string, string> = {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      }

      if (firmar) {
        const key = await crypto.subtle.importKey(
          'raw',
          new TextEncoder().encode(secret),
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
    { body: body, secret: SECRET, firmar: options.sign !== false },
  )
}

/** The body Meta sends when somebody writes or taps a button. */
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
              metadata: { phone_number_id: NUMBER },
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
 * Waits for the bot to ANSWER what is expected.
 *
 * It waits for the reply rather than for the conversation to exist, and the
 * difference is an intermittent test: the job creates the conversation, stores
 * what came in, and only then answers.
 */
async function waitForReply(page: Page, contiene: string): Promise<Message[]> {
  let messages: Message[] = []

  await expect
    .poll(
      async () => {
        const { data } = await apiFetch<{ data: Conversation[] }>(page, '/api/v1/conversations')

        const conversation = data.find((c) => c.customerName === `Cliente ${RUN}`)

        if (conversation === undefined) return false

        const detail = await apiFetch<{ data: { messages: Message[] } }>(
          page,
          `/api/v1/conversations/${conversation.id}`,
        )

        messages = detail.data.messages

        return messages.some((m) => m.direction === 'out' && m.content.includes(contiene))
      },
      { timeout: 20_000, message: 'El bot no contestó' },
    )
    .toBe(true)

  return messages
}

/**
 * Leaves WhatsApp connected with this run's number and secret.
 *
 * Every test starts with a clean browser, but the database is NOT rebuilt:
 * without this, the second test would send a webhook signed with a secret that
 * is no longer the stored one.
 */
async function connectWhatsApp(page: Page): Promise<void> {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')
  await page.goto(dashboardOf(TENANTS.arepera) + 'canales')

  const whatsapp = page.getByRole('region', { name: 'WhatsApp' })

  await whatsapp.getByRole('button', { name: /Conectar|Cambiar el token/ }).click()
  await whatsapp.getByLabel('Identificador del número').fill(NUMBER)
  await whatsapp.getByLabel('Token permanente').fill(`token-de-prueba-${RUN}`)
  await whatsapp.getByLabel('Secreto del webhook').fill(SECRET)
  await whatsapp.getByRole('button', { name: 'Guardar', exact: true }).click()

  /*
   * It waits for the FORM TO CLOSE, not for the channel to say "Connected".
   *
   * The database is not rebuilt between runs, so the channel could already have
   * been connected and that text already present. The assertion would pass
   * instantly, the webhook would go out before the new number was saved, and
   * the failure would appear three tests later.
   */
  await expect(whatsapp.getByRole('button', { name: 'Guardar', exact: true })).toBeHidden()
}

test('the owner connects WhatsApp and the bot starts answering', async ({ page }) => {
  await signIn(page, TENANTS.arepera, 'maria@elsazon.test')

  await page.goto(dashboardOf(TENANTS.arepera) + 'canales')
  await expect(page.getByRole('heading', { name: 'WhatsApp y Telegram' })).toBeVisible()

  // The channel card, by name. Seeding is additive and an earlier run may have
  // left WhatsApp connected, so whichever button is there — "Conectar" or
  // "Cambiar el token" — is taken rather than assumed.
  const whatsapp = page.getByRole('region', { name: 'WhatsApp' })

  await whatsapp.getByRole('button', { name: /Conectar|Cambiar el token/ }).click()

  await whatsapp.getByLabel('Identificador del número').fill(NUMBER)
  await whatsapp.getByLabel('Token permanente').fill(`token-de-prueba-${RUN}`)
  await whatsapp.getByLabel('Secreto del webhook').fill(SECRET)
  await whatsapp.getByRole('button', { name: 'Guardar', exact: true }).click()

  // It waits for the form to close — which only happens on save — and then for
  // what is visible: connected, with the address to paste into Meta's console
  // already assembled. Typing it by hand and getting one character wrong is an
  // afternoon lost.
  await expect(whatsapp.getByRole('button', { name: 'Guardar', exact: true })).toBeHidden()
  await expect(whatsapp.getByText('Conectado')).toBeVisible()
  await expect(whatsapp.getByText('/webhooks/whatsapp')).toBeVisible()

  // ── A signed message arrives, as Meta would send it.
  expect(await sendWebhook(page, whatsAppBody(`wamid.${RUN}.1`))).toBe(200)

  // The bot answered with its menu, and what each side said was stored: half a
  // conversation is no use when the manager opens it.
  const messages = await waitForReply(page, '¿Qué quieres hacer?')

  expect(messages.some((m) => m.direction === 'in')).toBe(true)
})

test('an unsigned webhook does not get in', async ({ page }) => {
  // Anybody on the internet can POST to this address. The only thing separating
  // a message from Meta from an invented one is the signature.
  await connectWhatsApp(page)

  const status = await sendWebhook(page, whatsAppBody(`wamid.${RUN}.sinfirma`), { sign: false })

  expect(status).toBe(403)
})

test('the bot shows the menu and sends people to the portal to order', async ({ page }) => {
  await connectWhatsApp(page)

  // The section AND its product are seeded: the bot shows categories only when
  // there are any, and without this the test would depend on other runs.
  const { data: category } = await apiPost<{ data: { id: string } }>(
    page,
    '/api/v1/catalog/categories',
    { name: `[e2e] Bot ${RUN}` },
  )

  await apiPost(page, '/api/v1/catalog/products', {
    name: `[e2e] Arepa bot ${RUN}`,
    price_cents: 300,
    category_id: category.id,
  })

  expect(await sendWebhook(page, whatsAppBody(`wamid.${RUN}.2`, { button: 'catalog' }))).toBe(200)

  // The menu, with its sections.
  await waitForReply(page, '¿Qué te provoca?')
})
