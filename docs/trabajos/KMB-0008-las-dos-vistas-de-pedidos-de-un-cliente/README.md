---
codigo: KMB-0008
titulo: Las dos vistas de pedidos de un cliente
tipo: arreglo
estado: hecho
fecha: 2026-08-29
toca: [web/apps/portal, web/apps/dashboard/src/screens/CustomersScreen.tsx, api/src/Modules/Portal]
relacionados: [KMB-0009, KMB-0010]
---

# KMB-0008 · Las dos vistas de pedidos de un cliente

## Por qué

Dos pantallas cuentan lo que un cliente pidió —la que ve él mientras espera
(`/p/{token}`) y su ficha en el panel— y las dos estaban a medias. Pero el
problema no era estético: **tiraban datos que el servidor ya mandaba**.

`TrackedOrder` viajaba con `placedAt`, `expiresAt`, `deliveryAddress`,
`serviceTypeLabel` y `notes`; la pantalla no pintaba ninguno. `Shop` traía
`phone`, `logoUrl` y `brandColor`, y el seguimiento no usaba ninguno —
`brandColor` no se usaba en **ningún sitio** del portal.

Y dos fallos que no eran de gusto:

- **El paso actual se veía como terminado.** `steps[].done` viene acumulativo,
  así que el cliente leía «✓ Lo estamos haciendo» y no podía saber si le
  faltaba o ya estaba.
- **El plazo del pago no se decía en ninguna parte.** Un pedido sin comprobante
  tiene `expires_at` y una tarea lo cancela cada diez minutos. El cliente no veía
  ningún reloj: **se le moría el pedido en la mano sin un aviso**.

## Qué se hizo

En el seguimiento: cabecera con logo y marca, el número de pedido como
encabezado real de la página, cuánto lleva esperando, la dirección de entrega
—el cliente es el único que puede ver que está mal—, el pedido con sus notas, y
un botón para llamar al local. El plazo del pago va **lo primero**, en rojo por
debajo de cinco minutos.

En la ficha del panel: cada pedido **abre su detalle** (el `id` llegaba y se
descartaba), con fecha, canal y estado con su color; y `lastOrderAt` en la
lista, que ordenaba por él sin enseñarlo.

En el servidor, tres campos: `waitingSeconds` y `expiresInSeconds` **calculados
por el servidor**, y `timezone` en `/me`.

## Qué se descartó, y por qué

**Derivar los plazos de `placedAt` / `expiresAt` en el cliente.** Es lo obvio y
es lo peligroso: el reloj de un teléfono en la calle está mal más a menudo de lo
que parece, y `expiresInSeconds` es el número que decide si alguien pierde su
pedido. El comentario de `OrderResource.php` ya explicaba esta decisión para el
tablero; aquí vale más.

**Un temporizador de un segundo para la cuenta atrás.** La pantalla ya refresca
cada 10 s, así que la cuenta se reancla sola. Un ticker es batería de alguien
que está esperando en la calle.

**El color de marca en botones y estados.** `brandColor` es un hexa que escribe
el negocio: una marca verde en un estado diría «listo» sin serlo. Se aplica sólo
a la franja del nombre, y `brandSurface()` lo descarta si el texto no contrasta
4.5:1 encima.

**Paginar el histórico del cliente.** Treinta pedidos es un histórico razonable
y `ordersCount` ya viajaba: bastaba con decir «Los 30 últimos de 47».

## Qué falló por el camino

**Un pedido entregado se pintaba como si siguiera en la plancha.** `ready` se
marca con `ready_at`, y una venta de mostrador que se entrega directa nunca pasa
por ahí: los pasos llegan `[hecho, hecho, NO, hecho]`. La regla ingenua —«el
último `done` es el actual»— dejaba un hueco y marcaba «Entregado» como en
curso. **Lo vi en una captura, no en una prueba.** Terminado lo dice el último
paso; el avance son los seguidos desde el principio.

**El primer intento metió el subtítulo dentro de la franja de marca**, y
«Abierto ahora» en verde sobre un naranja oscuro no se leía. El subtítulo bajó
al neutro, que es el fondo para el que se eligieron los colores de estado.

**El selector de foto decía «Choose File»** en inglés, puesto por el navegador,
en el paso donde alguien intenta pagar.

**Dije que el reporte de ventas enseñaba «counter» en crudo y me equivoqué**:
había una tabla correcta en `api/reports.ts`. El problema real eran dos tablas,
no una sin traducir; quedó una sola en `api/orders.ts`, que es donde vive el
concepto.

## Cómo se verificó

```bash
make check
./e2e/run.sh tests/portal.spec.ts
```

Cuatro pruebas nuevas en `PortalTest` —los dos contadores, el plazo vencido en
cero y no negativo, y el pedido sin plazo en `null`— y la E2E del plazo, que es
la información sin la cual el pedido se muere solo.

Y a ojo, las tres pantallas del portal en 390×844, que es donde se usa.

## Lo que quedó fuera

El botón de contacto va con `tel:` y no a WhatsApp: `phone` es texto libre y
armar un `wa.me` obliga a adivinar el código de país. Cuando haya un teléfono
normalizado, se cambia.
