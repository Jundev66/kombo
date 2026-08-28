# `web/` · React 19 · Vite 8 · TypeScript 7 · Tailwind 4

Lee antes el `CLAUDE.md` de la raíz.

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
5. **Color con significado.** Un acento de marca (naranja) y neutros. Verde,
   ámbar y rojo están reservados para estado — por eso *Cobrar* es naranja y no
   verde: cobrar no es un estado.
6. **Densidad por rol.** La caja respira. El panel es denso, que es donde se
   compara. La cocina va oscura porque se lee de lejos.
7. **Cero jerga.** «Cuánto debería haber», no «arqueo esperado».

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
