---
codigo: KMB-0011
titulo: Toda la app responsive, no sólo del teléfono hacia abajo
tipo: arreglo
estado: hecho
fecha: 2026-08-29
toca: [web/packages/ui/src/components/layout.tsx, web/packages/shell/src/AppShell.tsx, web/apps/dashboard, web/apps/portal, web/apps/admin]
relacionados: [KMB-0010, KMB-0008]
---

# KMB-0011 · Toda la app responsive, no sólo del teléfono hacia abajo

## Por qué

El tablero de pedidos se veía feo en un portátil, y al medirlo el problema
resultó ser general. El sistema estaba diseñado para el teléfono —lo cual sigue
siendo correcto: la caja es una PC de mostrador y la cocina una tablet barata—
pero **de ahí para arriba fallaba de dos maneras opuestas**:

| | Ancho usado a 1512 px | Síntoma |
|---|---|---|
| Panel | **51 %** | Columna estrecha con dos márgenes grises; cada tarjeta estirada a 736 px para cuatro líneas |
| Super administración | 59 % | Lo mismo |
| Portal | **100 %** | Sin tope: filas de teléfono estiradas a 1480 px, un producto por franja con dos palabras en la esquina |
| Caja y cocina | 100 % | Correcto — son herramientas a pantalla completa |

En el tablero de pedidos eso significaba ver **siete pedidos** de veintidós
abiertos. Los que no se ven son los que no se atienden.

## Qué se hizo

Dos primitivas en `packages/ui/src/components/layout.tsx`, para que doce
pantallas no resuelvan cada una lo suyo:

- **`<Page ancho>`** — `lectura` (`max-w-3xl`) para formularios y documentos,
  `tablero` (`max-w-7xl`) para listas, y sin tope para la caja y la cocina. El
  armazón ya no impone un ancho: **un tablero quiere ancho y un formulario no**.
- **`<CardGrid>`** — una columna en el teléfono, dos desde `md`, tres desde
  `xl`.

Con eso: el tablero de pedidos pasa a rejilla (21 pedidos a la vista), la carta,
los clientes, las categorías y los negocios del admin también, y las catorce
pantallas del panel declaran su ancho.

En el portal, la carta pasa a rejilla **con la foto arriba y grande** a partir
de tablet —en comida se elige con los ojos, que es el principio número uno— y
el carrito y el seguimiento se centran con tope.

## Qué se descartó, y por qué

**Una tabla densa en escritorio para los pedidos.** Se comparan mejor los
importes, y son dos maquetas que mantener para la misma pantalla — que es como
una de las dos se queda atrás.

**Dejar el panel en una columna y sólo apretar la tarjeta.** Lo más
conservador, y seguía desperdiciando media pantalla.

**Poner el tope de ancho en el armazón.** Es lo que había, y es justo lo que
impedía que un tablero fuera ancho sin llevarse por delante los formularios.

## Qué falló por el camino

**Los cortes por nombre de dispositivo estaban mal.** El primer intento usó
`lg`/`2xl`, y un portátil de 1512 px se quedaba en **dos columnas de 600 px** —
el mismo desperdicio de antes con un paso menos. Los cortes salen de medir la
tarjeta: una de pedido necesita ~320 px, así que dos caben a 768 y tres a 1280.

**En el portal, una tarjeta sin foto al lado de otra con foto** se estiraba para
igualar la altura y dejaba un cajón blanco con dos líneas arriba. Parecía rota.
Se resolvió con el mismo hueco gris que ya usaba la carta del panel — y de paso
deja claro qué producto no tiene foto, que es lo que más vende.

**La cabecera quedó desalineada del contenido.** Iba a todo el ancho con su
texto pegado al borde mientras el contenido empezaba doscientos píxeles más
adentro, como dos páginas pegadas. La barra va a todo el ancho; su contenido, al
mismo carril que la página.

**Y un detalle del proceso**: el guion que envolvía las pantallas en `<Page>`
emparejaba las etiquetas por posición y falló en dos archivos. Se rehízo
contando profundidad de `<div>`, que es lo que había que hacer desde el
principio.

## Cómo se verificó

```bash
make check
./e2e/run.sh
```

Y midiendo, que es lo que destapó el problema: un guion de Playwright que
recorre las cinco aplicaciones a 390, 820 y 1512 px e informa del ancho usado y
de si hay scroll horizontal. Antes: 51 %, 59 %, 100 %, 100 %, 100 %. Después,
todo aprovechado y sin scroll horizontal en ningún ancho.

## Lo que quedó fuera

La cocina y la caja no llevan tope de ancho a propósito: en un monitor muy
grande la cocina reparte tres columnas altísimas. Se decidió no tocarlo — esa
pantalla se lee de lejos y las columnas altas son lo que se quiere.
