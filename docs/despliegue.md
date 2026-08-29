# Desplegar Kombo

De un servidor vacío a un negocio tomando pedidos. Una hora larga la primera
vez, cinco minutos las siguientes.

Todo lo que hay aquí se copia y se pega. Donde diga `tudominio.com`, va el tuyo.

---

## Lo que hace falta antes de empezar

- **Un dominio.** Cualquiera. Los negocios cuelgan de él como subdominios.
- **Una cuenta de Cloudflare** (la gratuita sirve) con el dominio dentro.
- **Un VPS con Docker.** Con 2 núcleos y 4 GB va sobrado para los primeros
  clientes. Ubuntu o Debian recientes.

---

## 1. El servidor

```bash
# Como root, en el VPS recién creado
apt update && apt upgrade -y
curl -fsSL https://get.docker.com | sh

# Un usuario que no sea root para el día a día
adduser kombo && usermod -aG docker kombo
```

**El cortafuegos.** Sólo entran 22, 80 y 443:

```bash
ufw allow 22 && ufw allow 80 && ufw allow 443 && ufw enable
```

> El puerto de PostgreSQL **no** se abre. En `compose.prod.yml` la base de datos
> no publica puerto: sólo la alcanzan los otros contenedores. Una base de datos
> asomada a internet es la forma más común de perder los datos de un cliente.

---

## 2. Cloudflare

**DNS** — dos registros, los dos con la nube **naranja** (proxied):

| Tipo | Nombre  | Contenido        |
|------|---------|------------------|
| A    | `*`     | la IP del VPS    |
| A    | `admin` | la IP del VPS    |

El comodín es lo que hace que dar de alta un cliente cueste una fila en la base
de datos: `elsazon.tudominio.com` funciona sin tocar el DNS, ni nginx, ni
certificados.

> El certificado gratuito de Cloudflare cubre **un** nivel de subdominio
> (`elsazon.tudominio.com`), que es justo el que usamos. Nada cuelga más abajo.

**SSL/TLS** → modo **Full (strict)**. Cualquier otro modo o deja el tramo
Cloudflare↔servidor en claro, o no comprueba el certificado del servidor.

**El certificado de origen** — *SSL/TLS · Origin Server · Create Certificate*.
Acepta lo que propone (incluye `*.tudominio.com` y `tudominio.com`) y guarda las
dos partes en el servidor:

```bash
mkdir -p ~/kombo/certs
nano ~/kombo/certs/origen.pem   # pega el certificado
nano ~/kombo/certs/origen.key   # pega la clave privada
chmod 600 ~/kombo/certs/origen.key
```

Caduca a los 15 años. No hay renovación que se rompa de madrugada.

---

## 3. Kombo

```bash
git clone <tu-repositorio> ~/kombo && cd ~/kombo
cp api/.env.production.example .env
```

Abre `.env` y rellena lo que pide. Son cinco cosas:

```bash
# La clave de la aplicación
docker run --rm php:8.5-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"

# Las dos contraseñas de la base
openssl rand -base64 24    # KOMBO_OWNER_PASSWORD
openssl rand -base64 24    # KOMBO_APP_PASSWORD
```

Y tus dominios: `KOMBO_ROOT_DOMAIN=tudominio.com`,
`KOMBO_ADMIN_HOST=admin.tudominio.com`.

> **`APP_KEY` no se cambia nunca.** Con ella se cifran las sesiones y los
> teléfonos de los clientes. Cambiarla deja esos datos ilegibles para siempre.
> Guárdala donde guardas lo que no se puede perder.

**Arrancar:**

```bash
docker compose -f compose.prod.yml up -d --build
```

La primera vez tarda: construye PHP con sus extensiones y compila las cinco
aplicaciones. Después, cada despliegue reutiliza casi todo.

Las migraciones **corren solas** al arrancar, y sólo las corre el proceso web.
Míralo:

```bash
docker compose -f compose.prod.yml logs -f api
```

---

## 4. Los planes y tu cuenta

```bash
cd ~/kombo

# Los planes: sin ellos no hay techos que aplicar ni módulos que encender.
# Los negocios de demostración NO se siembran: KOMBO_DEMO_TOOLS está apagado.
docker compose -f compose.prod.yml exec api php artisan db:seed --force

# Tu cuenta de administración. Pide la contraseña por teclado.
docker compose -f compose.prod.yml exec api php artisan plataforma:admin tu@correo.com
```

Entra en `https://admin.tudominio.com`.

---

## 5. El primer negocio

Desde la super administración: **Negocios · Nuevo**. Se le pone nombre, el
subdominio (`elsazon`), el plan, y el correo y la contraseña del dueño.

Al terminar existe todo esto:

```
https://elsazon.tudominio.com/          el portal — lo que ve el cliente
https://elsazon.tudominio.com/panel/    el panel del dueño
https://elsazon.tudominio.com/caja/     la caja
https://elsazon.tudominio.com/cocina/   la pantalla de cocina
```

Entra al panel con el correo del dueño y carga el menú. Para conectar WhatsApp
o Telegram: **[`canales.md`](canales.md)**.

---

## Comprobar que quedó bien

Cuatro cosas. Las cuatro se hacen desde un navegador y un terminal, y las cuatro
han fallado alguna vez en algún despliegue.

**1. El portal responde con candado.**

```bash
curl -sI https://elsazon.tudominio.com | head -1     # HTTP/2 200
```

**2. La administración no la abre la sesión de un negocio.** Entra al panel de
`elsazon`, y con esa misma sesión abre `https://admin.tudominio.com`: tiene que
pedirte entrar. Si no lo pide, `SESSION_DOMAIN` no está vacío.

**3. La aplicación ve la IP del visitante, no la de Cloudflare.** De esto
dependen todos los limitadores de intentos: si ven una sola IP, todos los
clientes comparten cubo y el primero que se equivoque de contraseña deja fuera a
los demás.

Entra a `https://admin.tudominio.com` con tu cuenta, y después:

```bash
# Tu IP pública de verdad
curl -s ifconfig.me; echo

# La que quedó escrita al entrar
docker compose -f compose.prod.yml exec postgres \
  psql -U kombo_owner -d kombo -tAc \
  "SELECT ip FROM platform_audit_log WHERE action = 'platform.login' ORDER BY created_at DESC LIMIT 1"
```

> Las dos tienen que **coincidir**. Si la segunda es una dirección de Cloudflare
> —`162.158.x`, `172.6x.x`, `104.x`— la cabecera no está llegando: revisa
> `real_ip_header` en `docker/nginx/prod.conf` y `trustProxies` en
> `api/bootstrap/app.php`.
>
> Esto **no** se puede comprobar mandando tú la cabecera `CF-Connecting-IP`:
> Cloudflare la reescribe al pasar, que es justamente lo que impide que un
> visitante se invente su IP para saltarse los límites.

**4. Un pedido llega a la cocina.** Haz un pedido en el portal, ponlo en
`/cocina/` de ese mismo negocio y comprueba que aparece la comanda.

Y una quinta, que se hace una sola vez pero vale por todas: **[restaura un
respaldo](respaldos.md)**. Un respaldo que nadie ha restaurado nunca no es un
respaldo.

---

## Actualizar

```bash
cd ~/kombo
git pull
docker compose -f compose.prod.yml up -d --build
```

Las migraciones corren solas y las aplicaciones se vuelven a publicar. Hay unos
segundos de corte mientras php-fpm reinicia.

> **Antes de una actualización con migraciones, un respaldo a mano:**
> ```bash
> docker compose -f compose.prod.yml exec api php artisan respaldos:hacer
> ```

---

## Cuando algo va mal

```bash
# Qué está corriendo
docker compose -f compose.prod.yml ps

# Los registros de la API
docker compose -f compose.prod.yml logs -f api

# Estado de la aplicación: versión, caché, conexiones
docker compose -f compose.prod.yml exec api php artisan about
```

**Después de tocar `.env` no basta con reiniciar.** La configuración está
cacheada dentro del contenedor:

```bash
docker compose -f compose.prod.yml up -d --force-recreate api queue scheduler
```

**Una pantalla en blanco después de desplegar** suele ser el navegador con el
`index.html` viejo en caché. Recarga forzada. Si persiste, mira que el servicio
`web` terminara bien:

```bash
docker compose -f compose.prod.yml logs web
```

---

## Sin despliegue automatizado

Se despliega a mano, con `git pull` y `up -d --build`. Con un cliente propio y
dos o tres más, es lo que corresponde: menos piezas que se rompen solas.
Automatizarlo es la conversación de más adelante, cuando desplegar varias veces
por semana empiece a doler.

Lo mismo con el **monitoreo**: hoy, que el servidor se cayó te enteras porque un
cliente te escribe. Con un puñado de negocios eso alcanza; a partir de ahí hace
falta algo que avise.
