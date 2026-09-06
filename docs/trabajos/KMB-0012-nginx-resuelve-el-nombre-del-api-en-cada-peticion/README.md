---
codigo: KMB-0012
titulo: nginx resuelve el nombre del api en cada petición
tipo: arreglo
estado: hecho
fecha: 2026-09-06
toca: [docker/nginx, compose.prod.yml, e2e/run.sh]
relacionados: []
---

# KMB-0012 · nginx resuelve el nombre del api en cada petición

## Por qué

Las pruebas de usuario en GitHub Actions daban **68 fallidas y 2 pasadas**. En
local, la misma suite pasaba 82/82. Las 68 mostraban exactamente la misma
pantalla: «No se pudo contactar al servidor».

Y ahí empezaba el engaño. La API estaba **viva**: el paso que espera a que
responda había dado 200 sobre `/up` un minuto antes. Su log no tenía un solo
error — ni un 500, ni un 404, nada más que `fpm is running, ready to handle
connections`. Todo lo de Vite respondía 200. Sólo `/api/*` fallaba, y fallaba
antes de llegar a PHP.

La causa estaba en una línea que llevaba ahí desde el primer día:

```nginx
upstream php { server api:9000; }
```

Un bloque `upstream` con un nombre de máquina lo resuelve **una vez, al cargar
la configuración**, y se queda con esa IP para siempre. A mitad de la corrida,
Compose recreó el contenedor `api` — lo hace por su cuenta, reconciliando el
grafo de dependencias cuando se invoca `docker compose run` — y `api` volvió con
una dirección nueva. nginx siguió marcando la vieja.

Lo que convierte el síntoma en un acertijo es lo que pasó después: esa dirección
liberada se la quedó **el contenedor donde corre el navegador**, que arrancó
justo detrás. Por eso el log dice esto, con la misma IP de cliente y de destino:

```
"GET /api/v1/me HTTP/1.1" 502
connect() failed (111: Connection refused) while connecting to upstream,
  client: 172.18.0.6, upstream: "fastcgi://172.18.0.6:9000"
```

No es un tiempo agotado, que habría apuntado a una API lenta: es «conexión
rechazada» por algo que nunca escuchó ahí.

En producción el mismo fallo es peor, y estaba armado: cada
`docker compose -f compose.prod.yml up -d` recrea `api`, nginx no tiene motivo
para recrearse, y el sitio entero — todos los negocios — daría 502 hasta que
alguien lo reiniciara a mano.

## Qué se hizo

`docker/nginx/default.conf` y `docker/nginx/prod.conf` declaran el DNS interno de
Docker y pasan cada dirección por una **variable**. Con un literal, nginx
resuelve al cargar; con una variable, resuelve al servir:

```nginx
resolver 127.0.0.11 valid=10s ipv6=off;
map $host $php { default "api:9000"; }
...
fastcgi_pass $php;
```

Tres detalles que no son adorno. `valid=10s` **hace falta**: el DNS de Docker
responde con un TTL de diez minutos, más que toda la suite, así que sin él se
enviaría el mismo fallo con pasos de más. `ipv6=off` porque la red es IPv4 y la
consulta AAAA sólo añade maneras de fallar. Y los bloques `upstream` hay que
**borrarlos**, no dejarlos al lado: con una variable, nginx busca primero entre
los `upstream` declarados y sólo cae al `resolver` si no encuentra el nombre.

Los cinco upstreams de Vite reciben el mismo trato aunque no fueran los que
fallaron. Es el mismo fallo latente, y en desarrollo muerde igual de mal:
recrear el contenedor `dashboard` a mano deja `/dashboard/` en 502 mientras el
log de Vite se ve perfectamente sano.

Al pasar por variable se pierden balanceo, `keepalive` y `max_fails`. Cada
bloque tenía un solo servidor y no había `keepalive` en ninguna parte, así que
no se pierde nada real — pero conviene saberlo antes de volver a añadirlos.

`compose.prod.yml` traía además un montaje roto: seguía apuntando a
`seguridad.conf`, renombrado a `security.conf` en el paso a inglés, y `prod.conf`
lo incluye ocho veces. nginx en producción **no arrancaba** desde entonces.
Corregido, y con `NGINX_ENVSUBST_FILTER: '^KOMBO_'` para que la sustitución de
la plantilla no pueda comerse una variable de nginx el día que colisione un
nombre.

`e2e/run.sh` espera ahora a que nginx alcance la API **dentro** del
`docker compose run`, porque es ese comando el que puede acabar de recrear
`api`. No hacía falta para arreglar esto; sirve para que la próxima vez que ese
salto se rompa salga una línea en castellano en vez de 68 pruebas rojas
señalando al frontend.

## Qué se descartó, y por qué

**Reiniciar nginx antes de las pruebas.** Tapa el síntoma en CI y deja
producción rota igual. Además la recreación ocurre *durante* el
`docker compose run`, o sea después del reinicio: no habría funcionado ni en CI.

**`--no-deps` en `docker compose run`.** Evitaría la recreación, pero se saltaría
`e2e-install`, que es quien instala `node_modules` en el volumen, y la suite
correría contra un directorio vacío.

**Reordenar los pasos del CI para que la recreación cayera antes.** Codificaría
un detalle interno de Compose que ni siquiera está identificado. Una línea cuya
justificación es «compose hace algo que no supimos nombrar» es una línea que
nadie se atreverá a borrar nunca.

**El parámetro `resolve` de `upstream … server`**, que es justo para esto, es
exclusivo de NGINX Plus. En la imagen libre no existe otra vía que la variable.

## Qué falló por el camino

**Se dio por bueno un diagnóstico equivocado.** Como `/up` respondía 200 se
llegó a decir que la API estaba bien y el problema era de las pruebas. Lo era la
API: `/up` se consultó *antes* de la recreación, y nunca se volvió a mirar.

**Casi se rompe la reproducción local.** `docker compose up -d --force-recreate
api` a secas suele devolver **la misma IP**, porque el IPAM de Docker reutiliza
la más baja libre. Entonces el fallo no se reproduce y uno concluye que no
existe. Hay que ocupar la dirección liberada a propósito, con
`docker run --network kombo_default --ip <la de antes>`.

**Las 2 pruebas que «pasaron» no pasaron por lo que parecía.** No es que no
tocaran la API: `web/apps/admin/src/App.tsx` hace
`if (session.data == null) return <LoginScreen/>` sin mirar `session.isError`,
así que un backend muerto y «no hay sesión» se pintan igual. `admin.spec.ts:28
platform administration does not open without signing in` está verde contra una
API completamente caída, o sea que no comprueba lo que dice su nombre.
`packages/shell/src/Boot.tsx` sí distingue los tres estados, y por eso el lado de
los negocios sí mostró el error. Queda fuera de este trabajo.

## Cómo se verificó

El experimento y su control, sobre el mismo estado roto:

```bash
docker compose up -d
docker compose exec nginx getent hosts api      # 172.20.0.13
docker compose stop api
docker run -d --rm --name kombo-ip-squat --network kombo_default \
  --ip 172.20.0.13 --entrypoint sleep node:24-alpine 300
docker compose start api                        # api se muda a 172.20.0.12
```

Con la configuración vieja: `getent` **dentro del contenedor de nginx** devuelve
`172.20.0.12` y nginx marca `172.20.0.13`. **502 diez de diez**, sin recuperarse.
Con la nueva, tras un `nginx -s reload` y sin ningún otro cambio: **200 diez de
diez**. Lo mismo con `pos`, y las referencias que emite (`/pos/@vite/client`,
`/pos/src/main.tsx`) salen idénticas: la URI pasa entera, que era el riesgo de
mover un `proxy_pass` a una variable.

`prod.conf` se validó pasándolo por la sustitución de la propia imagen:
`${KOMBO_*}` se convierten y `$php`, `$tenant`, `$uri` y `$host` llegan intactos.
Con los montajes corregidos, `nginx -t` pasa; con el montaje que había, muere con
`open() "/etc/nginx/snippets/security.conf" failed`.

Y las puertas de siempre: `./e2e/run.sh` y `make check`.

## Lo que quedó fuera

El panel de administración debería distinguir «servidor caído» de «no hay
sesión», como ya hace `Boot.tsx`. Es un arreglo aparte, con su prueba — y con la
prueba de admin reescrita, porque hoy pasa por la razón equivocada.
