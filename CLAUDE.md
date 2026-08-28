# Kombo · mapa del repositorio

Léelo entero antes de tocar nada. Está escrito para que cualquiera —persona o
IA— pueda trabajar aquí sin haber estado en la conversación donde se decidió
todo esto.

---

## 1. Qué es

Sistema de pedidos de comida **multi-negocio**. Un solo despliegue atiende a
todos los clientes; cada negocio entra por su subdominio
(`elsazon.localhost:8010`) y ve únicamente sus datos, **garantizado por Row
Level Security de PostgreSQL, no por buena intención en el código**.

Un pedido puede entrar por tres puertas —el portal del cliente, un bot de
WhatsApp o Telegram, o la caja del mostrador— y las tres desembocan en la
**misma pantalla de cocina**. Ese recorrido es la razón de ser del producto.

Doble moneda: todo se guarda en **centavos de USD** y el bolívar es una
presentación calculada con la tasa del día, **congelada en cada documento**.

---

## 2. Las dos reglas de oro

> **1. Nunca se escribe código para un solo negocio.**

Ante «el cliente X necesita…», sube por los cuatro escalones y detente en el
primero que resuelva:

| # | Escalón | Qué se toca |
|---|---|---|
| 1 | ¿Ya es configurable? | Nada. Se enseña dónde. |
| 2 | ¿Puede ser una opción? | Un `Setting` del manifiesto, con el valor por defecto **igual al comportamiento de hoy**. Todos la reciben, nadie lo nota. |
| 3 | ¿Puede ser un interruptor? | Un `Setting` booleano, `false` por defecto. |
| 4 | ¿Puede ser un módulo? | Manifiesto, tablas, casos de uso, rutas, permisos. |

Si no cabe en ninguno, **la respuesta es no** — y se dice, con la razón por
delante. `NoPerTenantCodeTest` impide que exista la quinta opción.

> **2. El sistema no emite documentos fiscales.**

La caja emite **notas de entrega**, con su correlativo propio y con
`No es una factura` impreso. No calcula IVA como débito fiscal, no lleva libro
de ventas y no numera con rangos de la autoridad. Hay un puerto
`FiscalDocument` con implementación nula por si algún día un negocio se
homologa; mientras no exista ese adaptador, **no hay opción escondida que
convierta una nota en factura**.

Una nota por pedido, y no se reemite: por eso **anular el papel es anular la
venta entera** (`counter.void`), y no hay un `notes.void` aparte. Anular no
libera el número — un correlativo que se reutiliza deja de identificar nada.

---

## 3. Los invariantes, y quién vigila cada uno

Ninguno se sostiene con buenas intenciones. **Cuando una de estas pruebas
falla, la respuesta casi nunca es cambiar la prueba.**

| # | Invariante | Quién lo vigila |
|---|---|---|
| 1 | Ningún código compara contra un negocio concreto | `tests/Architecture/NoPerTenantCodeTest.php` |
| 2 | Toda tabla de negocio: `tenant_id`, RLS **activado y forzado**, política `tenant_isolation`, índices que **empiezan** por `tenant_id`, FK **compuestas** | `tests/Architecture/SchemaGuardTest.php` · se cumple usando `TenantSchema::create()` |
| 3 | El dominio no importa el framework. `Platform` no conoce `Modules`. Un módulo no importa entidades de otro | `tests/Architecture/BoundariesTest.php` |
| 4 | No se puede leer datos de otro negocio por ninguna vía | `tests/Isolation/` |
| 5 | El frontend no decide nada: menú, rutas y botones salen de `GET /api/v1/me` | `tests/Feature/CapabilitiesTest.php` |
| 6 | Presupuesto de arranque en gzip: portal y caja ≤180 KB, panel y admin ≤220 KB, cocina ≤120 KB | `web/scripts/check-bundle-size.mjs` (rompe el build) |
| 7 | `audit_log` es de sólo inserción | `tests/Isolation/AuditLogImmutabilityTest.php` |

---

## 4. Mapa

```
api/                      Laravel 13 · PHP 8.5
  src/Platform/           EL MOTOR. No depende de ningún módulo.
    Tenancy/              Subdominio → negocio, contexto, RLS, TenantSchema
    Auth/  Capabilities/  Subscription/  Audit/  Modules/
  src/Modules/            Los módulos, cada uno en cuatro capas
  src/Shared/             Money, ExchangeRate — value objects compartidos
  app/Models/             Eloquent (infraestructura, por módulo)
  tests/{Architecture,Isolation,Feature,Unit}

web/                      Monorepo npm workspaces
  apps/portal/            Cliente final   → /          (≤180 KB)
  apps/caja/              Mostrador       → /caja/     (≤180 KB)
  apps/panel/             Dueño           → /panel/    (≤220 KB)
  apps/kds/               Cocina          → /cocina/   (≤120 KB)
  apps/admin/             Plataforma      → admin.*    (≤220 KB)
  packages/{ui,shell,api-client}

e2e/                      Pruebas de usuario con Playwright, en contenedor
docker/                   nginx (comodín *.localhost), php, postgres (init RLS)
compose.yml               Todo el entorno
```

---

## 5. Las tres ideas que hay que entender para no romper nada

### 5.1 El aislamiento vive en la base de datos, no en el código

Dos usuarios de PostgreSQL: `kombo_owner` (dueño del esquema, superusuario,
**sólo** para migraciones) y `kombo_app` (con el que conecta la aplicación,
**sin `BYPASSRLS`**). La suite corre como `kombo_app` — si corriera como el
dueño, las pruebas de aislamiento pasarían en verde con RLS completamente roto.

La política usa `nullif(current_setting('app.tenant_id', true), '')`: sin
contexto, la comparación da `null` y devuelve **cero filas**. El modo de fallo
es negar.

### 5.2 Módulo ≠ carpeta. Es una carpeta **más un manifiesto**

De `ModuleManifest` sale todo: activar un módulo es **una fila en
`tenant_modules`, sin desplegar**; sus permisos aparecen y desaparecen con él;
el panel genera su formulario de configuración solo.

Las rutas de un módulo **no van en `routes/api.php`**: las declara su
manifiesto y las carga `PlatformServiceProvider` bajo el middleware
`module:{codigo}`.

Un módulo apagado responde **404, no 403** (que no exista para ese negocio es
información sobre su contrato). Un permiso que falta responde **403** (que el
usuario no pueda es decisión de su propio dueño, y se le dice claro).

### 5.3 `GET /api/v1/me` es el eje

El servidor combina **plan (techo) × `tenant_modules` (encendidos) ×
`tenant_settings` (comportamiento)** y devuelve el resultado ya resuelto. El
frontend pinta menú, rutas y botones a partir de eso. **No existe una lista de
módulos escrita en React** — por eso `ModuleCode` es `string` y no una unión de
literales: escribirla se sentiría más seguro y sería exactamente el error.

La comprobación de acceso es siempre la conjunción: **módulo encendido Y
permiso concedido**.

---

## 6. El hardware es modesto, y eso decide cosas

La mayoría de estos negocios corren la caja en una PC vieja de mostrador y la
cocina en una tablet barata, muchas veces con mala conexión. De ahí salen
decisiones que si no se explican parecen arbitrarias:

- **Presupuesto de bundle que rompe el build.** Cada 100 KB de más son
  segundos de pantalla en blanco con un cliente esperando.
- **Polling cada 5 s en la cocina, no websockets.** Una tablet con wifi malo se
  recupera sola de un sondeo; de un socket caído, no.
- **Un solo worker de cola.** Cuatro peleando por dos núcleos hacen lento
  hasta lo que el cajero está esperando.
- **Índices que sirven a la consulta exacta**, no por si acaso.
- **Opcache con JIT** y caché de rutas grande: no recompilar el mismo PHP en
  cada petición es lo que más rinde en una máquina lenta.
- **Las pruebas unitarias no arrancan Laravel.** Cientos de milisegundos por
  fichero que no compran nada.

---

## 7. Comandos

```bash
make up          # levantar todo
make up-lite     # sólo API, base, panel y cocina (máquinas justas)
make setup       # primera vez: clave, migraciones, base de pruebas
make demo        # sembrar los negocios de demostración

make check       # TODO lo que tiene que estar verde antes de decir "listo"
make test-arch       # los límites del diseño
make test-isolation  # que un negocio no vea los datos de otro
make e2e             # pruebas de usuario por el navegador
```

Direcciones en desarrollo:

```
http://elsazon.localhost:8010/          portal del cliente
http://elsazon.localhost:8010/panel/    panel del dueño
http://elsazon.localhost:8010/caja/     caja
http://elsazon.localhost:8010/cocina/   pantalla de cocina
http://admin.localhost:8010/            super administración
```

Puertos 8010 / 5436 / 6382, distintos a los de los otros proyectos de la
máquina, para que puedan estar todos levantados a la vez.

---

## 8. Trampas conocidas

**El resolutor cachea el negocio en Redis.** Cualquier operación que cambie el
identificador de un negocio (recrear la base, restaurar un respaldo) tiene que
invalidar esa caché. Si no, el síntoma engaña: `/me` responde bien —viene de
caché— y **todas** las consultas devuelven cero filas porque RLS filtra por un
identificador que ya no existe. Ante la duda: `php artisan cache:clear`.

**Los seeders corren como el dueño del esquema, que se salta RLS.** Una
consulta que DECIDE algo («¿ya existe esta fila?») dentro de un seeder no puede
apoyarse en el aislamiento ambiental: hay que filtrar por `tenant_id` a mano.

**`node_modules` vive en un volumen, no en el bind del host.** Rollup, oxc y el
motor de Tailwind traen binarios por plataforma, y unos instalados en macOS no
arrancan dentro del contenedor Linux.

**La imagen de PHP es Debian, no Alpine, y no se cambie sin leer esto.**
Instalar el cliente de PostgreSQL 18 en Alpine arrastra todo el toolchain de
LLVM y clang —`llvm22-libs` solo tarda casi seis minutos en ARM— porque el
paquete de desarrollo de Postgres depende de LLVM para su JIT. El build pasaba
de quince minutos y parecía colgado. En Debian, `libpq-dev` no arrastra nada de
eso: dos minutos, imagen de tamaño parecido, y glibc en vez de musl.

**PostgreSQL 18 cambió dónde va el volumen de datos.** El montaje es
`/var/lib/postgresql`, **no** `/var/lib/postgresql/data`: la imagen coloca los
datos en un subdirectorio por versión mayor para que `pg_upgrade --link`
funcione sin cruzar el límite del punto de montaje. Con el montaje antiguo el
contenedor arranca, imprime un error largo y se para.

**`page.request` de Playwright no ve el comodín de subdominios.** Resuelve
nombres con Node, no con Chromium, así que dentro del contenedor
`elsazon.localhost` no existe. Las llamadas a la API desde una prueba van con
`apiFetch()`, que hace `fetch` dentro de la página.

**Sanctum prefiere la sesión de navegador al token**, no al revés. La caja y la
cocina entran con un token de estación, pero si en ese mismo origen quedó una
cookie de sesión del panel, esa cookie gana y todo se ejecuta con **el usuario
del panel**. En las pruebas se nota como un permiso que debería faltar y no
falta: por eso `signOut(page)` antes de `enterRegister()` / `enterKitchen()`
cuando la prueba sembró algo con el dueño.

**Encender un módulo tiene que dar sus permisos a los roles base.** La siembra
es aditiva, así que un rol que ya existe no se vuelve a crear — pero sus
permisos **sí se reconcilian** en cada pasada (`insertOrIgnore` sobre el único
`(rol, permiso)`). Sin eso, el mostrador estrena la caja sin poder cobrar y el
fallo aparece en el peor sitio: con un cliente delante.

**La carta viene paginada** (`catalog.page_size`, 50 por defecto). Cualquier
pantalla que necesite la carta ENTERA —la caja— tiene que seguir las páginas.
Un producto que no está en la cuadrícula es un producto que no se puede vender,
y el cajero no tiene forma de saber que le falta.

---

## 9. Convenciones

- **Español** en documentación, comentarios, mensajes de error, commits y todo
  lo que ve el usuario. **Inglés** en identificadores, clases, tablas y rutas.
- **Cero jerga en la interfaz.** «Cuánto debería haber», no «arqueo esperado».
- **Los comentarios explican el porqué, no el qué.** Uno que repite lo que dice
  el código sobra; uno que explica qué se descartó y por qué, vale oro.
- **Dinero en centavos de USD**, siempre, con `Shared\Domain\ValueObjects\Money`.
  Nunca `float`.
- **Copiar el nombre, no referenciarlo** en documentos y comandas
  (`product_name`, `modifiers`): una comanda de hace seis meses debe leerse
  igual aunque el producto se haya renombrado o borrado.

---

## 10. Antes de decir que terminaste

1. ¿Corriste `make test-arch` y `make test-isolation`?
2. ¿Lo que hiciste sirve para **todos** los negocios, o para uno?
3. Si tocaste el esquema: ¿usaste `TenantSchema::create()` / `::references()` /
   `::index()`?
4. Si tocaste el frontend: ¿pasa `typecheck` y `size`? Y si tocaste un camino
   que recorre una persona, ¿corriste `make e2e`?
5. ¿El texto que ve el usuario está en español y sin jerga?
