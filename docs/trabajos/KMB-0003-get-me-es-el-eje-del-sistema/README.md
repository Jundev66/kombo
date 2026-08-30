---
codigo: KMB-0003
titulo: GET /api/v1/me es el eje, y el frontend no decide nada
tipo: decision
estado: hecho
fecha: 2026-08-25
toca: [api/src/Platform/Capabilities, web/packages/shell, web/packages/api-client]
relacionados: [KMB-0002, KMB-0010]
---

# KMB-0003 · `GET /api/v1/me` es el eje, y el frontend no decide nada

## Por qué

Con módulos que se encienden por negocio ([KMB-0002]), el frontend tiene que
saber qué pintar. La tentación es escribir en React la lista de módulos y qué
permiso hace falta para cada pantalla.

Eso rompe a la primera: la lista vive en el servidor y **cambia sin desplegar**.
Un módulo encendido esta mañana no aparecería hasta el siguiente build.

## Qué se hizo

El servidor combina **plan (techo) × `tenant_modules` (encendidos) ×
`tenant_settings` (comportamiento) × permisos del usuario** y devuelve el
resultado **ya resuelto**. El frontend pinta menú, rutas y botones a partir de
eso.

La comprobación es siempre la conjunción: **módulo encendido Y permiso
concedido** (`allows()` en `api-client`). El servidor aplica exactamente la
misma regla, así que la pantalla y la API no pueden discrepar.

Dos consecuencias que parecen detalles y no lo son:

- **`ModuleCode` es `string`, no una unión de literales.** Escribir
  `'orders' | 'kitchen' | …` se sentiría más seguro y sería justo el error:
  metería en el cliente una lista que sólo el servidor conoce.
- **El registro del frontend dice CÓMO se dibuja cada módulo, no cuáles
  existen.** Uno que el frontend todavía no sabe dibujar sencillamente no
  aparece, en vez de romper.

Y **nada se oculta con CSS**: si no puedes verlo, el servidor no lo manda. Poner
en gris lo que no existe es prometer algo que no hay.

## Qué se descartó, y por qué

**Un endpoint por pregunta** (`/can/orders.create`). Multiplica las peticiones en
el arranque, que es justo donde se paga en una PC de mostrador con mala
conexión.

**Un JWT con los permisos dentro.** Evita la llamada, pero un permiso revocado
sigue siendo válido hasta que caduque el token. En un local donde alguien deja
de trabajar un viernes, eso importa.

## Qué falló por el camino

**`/me` responde también SIN sesión**, y tuvo que ser así a propósito: la
pantalla de login necesita el nombre y el logo del negocio antes de que nadie
entre. Un login que dice «Kombo» en vez de «El Sazón» parece de otro producto.

**La caché del resolutor engaña.** Cuando el identificador de un negocio cambia
—recrear la base, restaurar un respaldo— `/me` sigue respondiendo bien porque
viene de caché, mientras **todas** las consultas devuelven cero filas porque RLS
filtra por un identificador que ya no existe.

## Cómo se verificó

```bash
make test    # CapabilitiesTest: el menú sale del servidor, no de React
make e2e     # entrar.spec.ts afirma la CAUSA además del síntoma
```

La prueba de navegador comprueba lo que se ve **y** lo que vino en `/me`. Una
que sólo mirase la pantalla pasaría en verde con el menú pintado a mano, que es
exactamente lo que viene a impedir.

## Lo que quedó fuera

`/me` se pide una vez al arrancar y se refresca al entrar y salir. No hay
invalidación en vivo: si el dueño enciende un módulo mientras un empleado tiene
la pantalla abierta, ese empleado lo ve al recargar.
