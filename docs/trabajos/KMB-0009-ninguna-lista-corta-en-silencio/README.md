---
codigo: KMB-0009
titulo: Ninguna lista corta en silencio
tipo: arreglo
estado: hecho
fecha: 2026-08-29
toca: [web/packages/ui, web/packages/api-client/src/paged.ts, web/apps/panel, web/apps/admin, api/src/Modules/Customers, api/src/Platform/Subscription]
relacionados: [KMB-0008]
---

# KMB-0009 · Ninguna lista corta en silencio

## Por qué

El `AGENTS.md` de este repositorio dice que cortar en silencio es el peor fallo
que puede tener una pantalla, y obliga a que cualquier lista con tope lo grite.
La cocina lo cumplía con `meta.hidden`. **Tres listas del panel no.**

| Lista | Tope | Qué veía el dueño |
|---|---|---|
| La carta | 50 por página | El negocio de demostración tiene **693 productos**; se veían 50 y nada lo insinuaba |
| Clientes | `limit(100)` | Sin `meta` y sin aviso |
| Lo que ha pedido un cliente | `limit(30)` | Debajo de «Ha pedido 47 veces». Los dos números se contradecían a la vista |

El de la carta era el peor porque **el servidor ya mandaba lo que hacía falta**:
`ProductController::index` devuelve `meta.page`, `meta.lastPage` y `meta.total`,
y `catalog.products()` los tiraba al leer sólo `r.data`.

Y la super administración tenía el problema contrario: su lista de negocios **no
tenía tope ninguno**, un `->get()` pelado. Con mil clientes se descarga entera.

## Qué se hizo

El patrón, una vez, y las cuatro listas lo usan:

- **`ListFooter`** en `packages/ui` — presentacional, no sabe nada de consultas.
  Dice «Se ven 50 de 693 productos» y trae la siguiente tanda.
- **`Paged<T>`** en `api-client` — la forma del `meta`, en el contrato y no
  repetida en cada `api/*.ts`. Cuando cada pantalla se describía el suyo, dos de
  las tres se olvidaban de leerlo.
- **`useInfiniteQuery`**, que ya viene con TanStack Query: sin dependencia nueva
  y sin acumular páginas a mano.

El histórico del cliente no se pagina: dice «Los 30 últimos de 47», que es lo
que faltaba.

## Qué se descartó, y por qué

**Sólo avisar, sin «Ver más».** Menos código, y un dueño que quiere **revisar**
su carta entera no podría: tendría que adivinar nombres en el buscador.

**Traerlo todo, siguiendo las páginas hasta el final** — que es lo que hace la
caja. La caja lo hace porque no le queda más remedio para vender: un producto
que no está en la cuadrícula es un producto que no se puede vender. El panel no
tiene esa obligación, y son 693 productos en una PC de mostrador.

## Qué falló por el camino

**El estado vacío de la carta mentía.** Buscar algo que no existe contestaba «Tu
carta está vacía · Añade lo que vendes» con un botón de añadir el primero,
delante de un dueño con 758 productos cargados. Son dos cosas distintas y decían
lo mismo.

**Escribí una prueba de paginación que pasaba por la razón equivocada.** Con 3
clientes la página 2 está vacía, y un array vacío es distinto de cualquier cosa.
Se rehízo insertando 101 clientes con **fechas distintas**: con la misma fecha el
orden entre páginas no está definido y la prueba pasaría según le pareciera a
PostgreSQL.

**Una E2E del admin empezó a fallar por este mismo cambio.** Buscaba su negocio
recorriendo la lista, que ahora está paginada. Se convirtió en una búsqueda — y
la regla quedó escrita en `AGENTS.md`, porque es la trampa que se repite.

## Cómo se verificó

```bash
make check
./e2e/run.sh
```

En `CustomersTest`, que la segunda página trae clientes distintos de la primera.
En `PlatformTest`, que la lista de negocios pagina. Y en `carta.spec.ts` el caso
que importa: con más productos de los que caben, la pantalla **dice cuántos hay**
y «Ver más» trae los siguientes.

A ojo: la carta dice «758 productos», el pie «Se ven 50 de 758» y el botón lleva
a 100.

## Lo que quedó fuera

El buscador sigue consultando al servidor en cada tecla, sin retraso. Con 693
productos no se nota; con veinte mil, sí.
