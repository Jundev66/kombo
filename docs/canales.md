# Conectar WhatsApp y Telegram

Cada negocio conecta **su** número y **su** bot. No hay un número de Kombo por
el que pasen los pedidos de todos: los mensajes salen a nombre del negocio, y
las credenciales son suyas.

Todo se pega en **Panel · Canales**. Esa pantalla calcula la dirección del
webhook —la que hay que dar de alta del otro lado— para que nadie la escriba a
mano. Esta guía explica de dónde sale cada cosa que pide.

> Conseguir las credenciales es un trámite del negocio con Meta o con Telegram.
> Aquí no hay forma de saltárselo: son ellos quienes verifican al comercio.

---

## Telegram — quince minutos

Empieza por aquí. No pide verificación de empresa, es gratis, y sirve para
comprobar que el bot funciona antes de meterse con Meta.

**1. Crear el bot.** En Telegram, habla con **@BotFather**:

```
/newbot
```

Te pide un nombre («Pizzería El Sazón») y un usuario que termine en `bot`
(`elsazon_pedidos_bot`). Devuelve un **token** así:

```
7891234567:AAHk9Lm2nOpQrStUvWxYz-1234567890abcdef
```

**2. Pegarlo en el panel.** *Panel · Canales · Telegram*:

| Campo                   | Qué va                                              |
|-------------------------|-----------------------------------------------------|
| Identificador del bot   | el usuario sin arroba: `elsazon_pedidos_bot`        |
| Token del bot           | el que dio BotFather                                |
| Secreto del webhook     | invéntalo, mínimo 8 caracteres — mejor 32           |

El **secreto** no lo da Telegram: lo eliges tú. Telegram lo devolverá en cada
mensaje y es lo que permite comprobar que el mensaje viene de Telegram y no de
alguien que descubrió la dirección.

```bash
openssl rand -hex 24
```

Al guardar, el panel muestra la dirección del webhook:

```
https://elsazon.tudominio.com/webhooks/telegram/elsazon_pedidos_bot
```

> Telegram no manda nada que identifique al bot dentro del mensaje, así que la
> cuenta va **en la dirección**. Es lo único que Telegram deja configurar por
> bot, y por eso cada negocio tiene la suya distinta.

**3. Darla de alta.** Una llamada, con el token y el secreto que acabas de
poner:

```bash
curl -s "https://api.telegram.org/bot<TOKEN>/setWebhook" \
  -d "url=https://elsazon.tudominio.com/webhooks/telegram/elsazon_pedidos_bot" \
  -d "secret_token=<EL-SECRETO>"
```

Respuesta buena: `{"ok":true,"result":true,"description":"Webhook was set"}`.

**4. Probar.** Escríbele `hola` al bot. Tiene que contestar con el menú.

Si no contesta:

```bash
curl -s "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"
```

`last_error_message` dice qué pasó. Los tres casos habituales:

- **`Wrong response from the webhook: 404`** — el identificador del bot en el
  panel no coincide con el de la dirección.
- **`SSL error`** — Cloudflare no está en *Full (strict)*, o el certificado de
  origen no quedó bien.
- **Nada, silencio** — el secreto del panel y el de `setWebhook` no coinciden;
  el mensaje llega y se descarta por firma. Vuelve a guardarlo en los dos sitios.

---

## WhatsApp — un rato más largo

WhatsApp Business Cloud API, de Meta. La parte lenta no es técnica: es que Meta
verifique al negocio.

**Antes de empezar el negocio necesita:**

- Una cuenta en [business.facebook.com](https://business.facebook.com).
- Un **número de teléfono que no esté en WhatsApp** — ni normal ni Business. Si
  ya lo está, hay que borrar esa cuenta primero, y eso borra su historial.

**1. La app.** En [developers.facebook.com](https://developers.facebook.com) →
*Crear app* → tipo **Empresa** → añade el producto **WhatsApp**.

**2. El número.** Dentro de WhatsApp → *Configuración de la API* → añadir número
de teléfono. Meta manda un código por SMS o llamada.

Ahí mismo aparece el **Identificador del número de teléfono** (`phone number
ID`): una tirada de dígitos como `109876543210987`. **No es el número de
teléfono.** Es lo que va en el panel.

**3. El token permanente.** El que Meta enseña primero **caduca en 24 horas** y
sirve para probar, no para trabajar. El de verdad:

*Business Settings · Usuarios · Usuarios del sistema* → crear uno con rol de
administrador → **Generar token** → elige la app y marca `whatsapp_business_messaging`
y `whatsapp_business_management`.

> Si el bot deja de contestar exactamente al día siguiente de conectarlo, es
> esto: se pegó el token de prueba.

**4. Pegarlo en el panel.** *Panel · Canales · WhatsApp*:

| Campo                        | Qué va                                       |
|------------------------------|----------------------------------------------|
| Identificador del número     | el `phone number ID`, no el número           |
| Token de acceso              | el permanente del usuario del sistema        |
| Secreto del webhook          | invéntalo, mínimo 8 caracteres               |

Al guardar, el panel muestra:

```
https://elsazon.tudominio.com/webhooks/whatsapp
```

> Aquí la dirección es **igual para todos los negocios**: WhatsApp sí manda el
> identificador del número dentro del mensaje, y con él se resuelve de quién es.

**5. Configurar el webhook en Meta.** WhatsApp → *Configuración* → *Webhooks* →
Editar:

- **URL de devolución de llamada**: la que muestra el panel.
- **Token de verificación**: el **mismo secreto** que pusiste en el panel.

Meta llama a esa dirección al guardar y espera que le devuelvan su desafío. Si
el secreto no coincide, no guarda.

Después, **Administrar** → suscribirse al campo **`messages`**. Sin esa
suscripción el webhook queda dado de alta y no llega ni un mensaje: es el fallo
más común, porque parece que todo quedó bien.

**6. Probar.** Escribe al número desde otro teléfono.

---

## Las diferencias que se notan

**Los botones.** WhatsApp acepta **tres** por mensaje y **20 caracteres** por
botón; Telegram no tiene ese límite. El bot arma los menús contra el límite más
estrecho, así que en Telegram se ven igual de cortos. Es a propósito: un mismo
menú que se ve distinto según por dónde escribas es un menú que hay que
mantener dos veces.

**La ventana de 24 horas de WhatsApp.** Meta sólo deja escribirle a alguien que
te escribió en las últimas 24 horas. Para salirse de eso hacen falta plantillas
aprobadas por Meta. En la práctica no estorba: los avisos que manda Kombo
—«recibimos tu pedido», «ya está listo»— van siempre dentro de esa ventana,
porque el cliente acaba de escribir.

---

## Cuando el bot no contesta

**Mira las conversaciones primero.** *Panel · Conversaciones*: si el mensaje
está ahí, llegó y el problema es de respuesta, no de recepción.

Si no está, el mensaje no entró. Por orden:

```bash
# ¿Llegó algo al servidor?
docker compose -f compose.prod.yml logs api | grep webhooks

# ¿Se está procesando la cola? Sin ella los mensajes entran y se quedan quietos.
docker compose -f compose.prod.yml logs -f queue
```

**«Webhook de un canal que no conocemos»** en el registro: el identificador
—`phone number ID` o usuario del bot— del panel no es el que manda la
plataforma. Está mal copiado.

> Un canal recién conectado puede tardar hasta **diez segundos** en empezar a
> responder: la resolución se cachea, y los fallos se cachean poco tiempo justo
> para que conectar un canal no obligue a esperar. Si a los diez segundos sigue
> callado, es otra cosa.

**Un mensaje contestado dos veces** no es un fallo de Kombo: Meta reintenta si
no le responden en 30 segundos. Los repetidos se descartan por identificador. Si
ves respuestas duplicadas de verdad, mira si la cola está atascada.

---

## Devolver una conversación a una persona

Desde *Panel · Conversaciones*, cualquiera con permiso puede escribirle
directamente a un cliente. Mientras alguien está respondiendo, el bot **se
calla** en esa conversación: dos voces contestando lo mismo confunden más que no
contestar.

Cuando el encargado termina, **Devolver al bot**. Si se le olvida, esa
conversación se queda sin bot — así que el botón está a la vista, no escondido
en un menú.
