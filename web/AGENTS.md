# `web/` · React 19 · Vite 8 · TypeScript 7 · Tailwind 4

Lee antes el `AGENTS.md` de la raíz.

## Cinco aplicaciones, un solo origen por negocio

| App | Ruta | Quién | Presupuesto |
|---|---|---|---|
| `portal` | `/` | Cliente final | 180 KB |
| `caja` | `/caja/` | Mostrador | 180 KB |
| `panel` | `/panel/` | Dueño y encargado | 220 KB |
| `kds` | `/cocina/` | Cocina | 120 KB |
| `admin` | `admin.*` | Plataforma | 220 KB |

Las cuatro primeras van bajo el **mismo origen** a propósito: el navegador
aísla `localStorage` e `IndexedDB` por origen, así que la caja de un negocio no
puede leer lo que guardó la de otro sin que nadie escriba una línea.

Builds separados por rol y no una sola aplicación con rutas: el cocinero no
tiene por qué descargar el panel de reportes para ver tres comandas.

**El portal es el único que se usa sin sesión**, y el único instalable: manifest,
service worker propio de cuarenta líneas, y una regla que no se negocia —**la API
nunca se cachea**—. Una carta guardada de ayer vende a precios de ayer, y un
pedido «en camino» que llegó hace media hora es peor que no decir nada.

**La caja y la cocina comparten puerta**: `TerminalGate` en `@kombo/shell`, con
sus dos pasos —alta del aparato con correo y contraseña, y PIN de la persona—.
Es una sola implementación a propósito: dos copias de una puerta acaban
divergiendo justo en el detalle que las hacía seguras. Y comparten origen, así
que comparten también el `localStorage` donde vive el token: entrar a la caja
deja abierta la cocina, lo cual en el local da igual —es el mismo negocio— pero
hay que tenerlo presente al escribir pruebas.

**Quién manda es `/me`, no `localStorage`.** `useDoorway` pregunta primero y de
ahí salen tres formas de entrar: `shift` (turno con PIN, lo de siempre),
`supervision` (hay sesión de navegador y no hay turno — el dueño mirando desde
su teléfono, sin alta y sin PIN) y `gate`. Las dos primeras enseñan en la
cabecera el nombre que devuelve el servidor, que no siempre es el de quien
tecleó el PIN: Sanctum prefiere la cookie al token, y antes esa diferencia no se
veía en ningún sitio.

## El frontend no decide nada

Menú, rutas y botones salen de `GET /api/v1/me`, que combina plan × módulos ×
ajustes × permisos **ya resueltos en el servidor**.

**No existe una lista de módulos escrita en React.** Por eso `ModuleCode` es
`string` y no una unión de literales: escribir `'orders' | 'kitchen' | …` se
sentiría más seguro y sería exactamente el error — metería en el cliente una
lista que sólo el servidor conoce, y que cambia sin desplegar.

El registro del frontend dice **cómo** se dibuja cada módulo, no cuáles
existen. Uno que no sabe dibujar sencillamente no aparece, en vez de romper.

**Nada se oculta con CSS.** Si no puedes verlo, el servidor no lo manda: un
módulo apagado responde 404. Poner en gris lo que no existe es prometer algo
que no hay.

Una entrada del registro puede llevar a **otra de las cinco aplicaciones**
(`href` en vez de `Screen`): así el panel enlaza la caja y la cocina en lugar de
obligar al dueño a saberse la URL. Se filtran con la misma regla que todo lo
demás —módulo encendido Y permiso—, así que un negocio sin mostrador no ve el
enlace. Nunca ocupan un sitio de la barra de pestañas: una pestaña promete
volver, y éstas se llevan fuera.

## Los principios del sistema visual

En una revisión se resuelve señalando uno de estos, no por gusto.

1. **El número es el protagonista.** Total, vuelto y saldo en la mayor escala
   de su pantalla (`--text-money-*`).
2. **Una acción primaria por pantalla.** En la caja siempre *Cobrar*, mismo
   sitio, mismo tamaño, alcanzable con el pulgar. Dos botones compitiendo es
   una pantalla mal dividida.
3. **Sin estados ocultos.** Lo que no aplica no existe. Lo único deshabilitado
   a propósito es lo que el plan bloquea, y va con su precio al lado.
4. **Toque antes que puntero.** 56 px mínimo (`--spacing-touch`). Nada de hover
   ni clic derecho. El foco visible **no** es opcional.

   Pero **empezar en el teléfono no es quedarse ahí**. Hay dos formas de fallar
   y el sistema tuvo las dos a la vez: el panel se quedaba en una columna
   estrecha con la mitad de la pantalla en gris, y el portal estiraba filas de
   teléfono a metro y medio. El ancho lo decide `<Page>` —`lectura` para
   formularios, `tablero` para listas, sin tope para la caja y la cocina— y las
   listas van en `<CardGrid>`, que crece a dos y tres columnas. En el tablero de
   pedidos eso es ver veintiuno de un vistazo en vez de siete.
5. **Color con significado.** Un acento de marca (naranja) y neutros. Verde,
   ámbar y rojo están reservados para estado — por eso *Cobrar* es naranja y no
   verde: cobrar no es un estado.

   El `brand_color` que escribe cada negocio **sólo tiñe la franja del nombre en
   el portal** (`ShopHeader`), nunca un botón ni un estado: es un hexa
   arbitrario, y una marca verde en un estado diría «listo» sin serlo. Va por
   `brandSurface()`, que elige el color de texto que contrasta y **descarta el
   color** si ninguno llega a 4.5:1. Todo lo demás de esa cabecera va debajo,
   sobre el neutro, para que los colores de estado sigan sobre el fondo para el
   que se eligieron.
6. **Densidad por rol.** La caja respira. El panel es denso, que es donde se
   compara. La cocina va oscura porque se lee de lejos.
7. **Cero jerga.** «Cuánto debería haber», no «arqueo esperado». Y en español
   de verdad: `plural()` para que no salga «1 productos», y el NOMBRE de las
   cosas y no su código —«Mostrador», no `counter`; «Negocio», no `negocio`—.

8. **Ninguna lista corta en silencio.** Con tope que no se puede seguir, se
   grita lo que no cabe (`meta.hidden`). Con paginación, `ListFooter` dice
   cuántas se ven de cuántas y trae más. Quien mira una lista cortada sin aviso
   no sabe que le falta algo, así que no lo busca.

**Los iconos son SVG en línea de `packages/ui/src/icons.tsx`, no una librería.**
Quince trazos que pesan menos de un kilobyte y se exportan uno a uno para que la
aplicación que no los usa no los arrastre; cualquier paquete de iconos se lleva
una parte del presupuesto que no vuelve. Eran emojis, y el problema era que cada
sistema dibuja el suyo: la misma pantalla se veía distinta en la tablet, en el
teléfono y en la PC del mostrador, y en un Android viejo alguno salía como un
cuadrito. Un icono que a veces es un cuadrito no es un icono.

## Los componentes no aceptan cualquier clase

`Button` expone `variant` y `size`, no un `className` que reemplace su fondo.
`className` sirve para **posicionar** (márgenes, ancho), no para redefinir
apariencia. ¿Hace falta una apariencia nueva? Es una **variante nueva en
`packages/ui`**, no una excepción en una pantalla.

## El presupuesto rompe el build

`npm run size` mide **gzip** y **sólo el camino de arranque** (el chunk de
entrada, su CSS, y lo que importa de forma estática). Lo que se carga bajo
demanda no cuenta, porque no retrasa la primera pantalla.

No es una métrica bonita: estos negocios corren la caja en una PC vieja y la
cocina en una tablet barata, muchas veces con mala conexión. **Subir un
presupuesto es una decisión de producto, no de build.**

## Tema

Todo en `packages/ui/src/theme.css`. Sin `tailwind.config.js`, sin PostCSS.

El modo oscuro va por **atributo** (`data-theme="dark"` en el `<html>`), no por
preferencia del sistema: la cocina es oscura siempre y la caja clara siempre.
Que dependiera del teléfono de quien la abrió sería un sorteo.

Las pantallas usan las **superficies semánticas** (`--surface`, `--text-muted`,
…) y nunca los neutros crudos. Así el modo oscuro es redefinir ocho variables y
no una segunda hoja de estilos que se desincroniza.

`@theme static` es obligatorio: sin `static`, Tailwind elimina las variables
que cree no usadas, y las superficies —que se referencian desde otras
variables, no desde clases— apuntarían a la nada.

## Comandos

```bash
npm run typecheck   # tipos de las cinco
npm run build       # construir las cinco
npm run size        # presupuesto — rompe si alguna se pasa
```
