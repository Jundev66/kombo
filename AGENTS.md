# Kombo · mapa del repositorio

Léelo entero antes de tocar nada. Está escrito para que cualquiera —persona o
IA— pueda trabajar aquí sin haber estado en la conversación donde se decidió
todo esto.

---

## 0. Si acabas de entrar

**Este archivo es la fuente.** `CLAUDE.md`, `GEMINI.md`, `.cursorrules` y
`.github/copilot-instructions.md` son punteros de tres líneas que traen aquí:
cada herramienta busca su propio nombre, y un solo texto es lo único que no se
desincroniza. Si mañana entras con otra que lee un nombre distinto, se añade el
puntero y ya está — **no se copia el texto**.

Hay uno por carpeta con lo específico de cada parte:
[`api/`](api/AGENTS.md), [`web/`](web/AGENTS.md), [`e2e/`](e2e/AGENTS.md).

Los cuatro pasos de entrada:

1. **Lee esto entero.** Las secciones 2 y 3 son las que impiden romper cosas.
2. **Lee el `AGENTS.md` de la carpeta** donde vas a trabajar.
3. **Busca en [`docs/trabajos/`](docs/trabajos/README.md)** si lo que vas a
   tocar ya se decidió antes. Es un trabajo por carpeta, con su código
   `KMB-XXXX` y el porqué de cada decisión. `make trabajos` lista el índice.
4. **Abre el tuyo**: `make trabajo t="lo que vas a hacer"`. Te da el siguiente
   código y la carpeta con la plantilla.

> **El registro no es burocracia: es la respuesta a «¿por qué está así?».**
> El código de un trabajo se cita en el comentario que lo necesita
> (`// Por qué esto no se pagina: KMB-0009`) y en el mensaje del commit. Quien
> llegue dentro de un año —o el próximo agente— tira de ese hilo en vez de
> deducirlo del diff.
>
> Lo que va aquí es lo que **no se deduce del código**: qué se descartó y por
> qué, qué falló al hacerlo, qué se verificó. Lo que sí se deduce del código no
> se escribe: un registro que repite lo que ya dice el diff envejece mal y nadie
> lo lee dos veces.

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
| 8 | Un negocio suspendido lee y exporta, pero no escribe — y eso se aplica en **un** middleware global, no en cada controlador | `tests/Feature/PlatformTest.php` |

---

## 4. Mapa

```
api/                      Laravel 13 · PHP 8.5
  src/Platform/           EL MOTOR. No depende de ningún módulo.
    Tenancy/              Subdominio → negocio, contexto, RLS, TenantSchema
    Auth/  Capabilities/  Subscription/  Audit/  Modules/
  src/Modules/            Los módulos, cada uno en cuatro capas
                          core · catalog · orders · kitchen · counter
                          documents · delivery · portal · channels
                          reports · customers
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

make trabajo t="Paginar la carta"   # abrir un trabajo nuevo en docs/trabajos/
make trabajos                       # regenerar su índice

make check       # TODO lo que tiene que estar verde antes de decir "listo"
make test-arch       # los límites del diseño
make test-isolation  # que un negocio no vea los datos de otro
make e2e             # pruebas de usuario por el navegador
```

Tareas programadas, que corren solas y conviene conocer:

```bash
php artisan suscripciones:revisar    # diaria: marca vencidos y suspende
php artisan pedidos:cerrar-vencidos  # cada 10 min: cierra los que no pagaron
php artisan demo:limpiar --horas=0   # sólo en demostración: vacía los tableros
```

Y una que **no** tiene horario porque es de despliegue, no periódica:

```bash
php artisan roles:reconciliar        # tras ampliar RoleCatalog o encender un módulo
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

Eso ya no pasa en silencio: las dos pantallas preguntan `/me` **antes** de abrir
la puerta y enseñan en la cabecera a quien el servidor diga que está operando —
con una banda de aviso si es una sesión de navegador y no un turno. La trampa
sigue existiendo; lo que cambió es que ahora se ve. Y como el servidor ya
aceptaba esa cookie (`RequirePermission` deja pasar las sesiones a propósito),
el dueño entra a supervisar su caja sin dar de alta el aparato ni teclear un
PIN.

**`role_permissions` sólo se escribía al dar de alta un negocio.** Ampliar
`RoleCatalog` servía a los negocios nuevos y a nadie más: el código salía, no
fallaba nada, y el encargado de un local de hace seis meses seguía sin poder.
Ahora la siembra vive en `RoleProvisioner` —el alta, el seeder de demostración y
los helpers de prueba usan ése— y `roles:reconciliar` la aplica a los que ya
existen. **Después de tocar el catálogo, hay que correrlo.**

Y ojo con qué módulos cuentan: **el núcleo nunca tiene fila en
`tenant_modules`** —no depende del plan y no se apaga—, así que leer sólo esa
tabla deja fuera `settings.manage`, `users.manage` y `audit.view`. La cuenta
correcta es `coreCodes()` + (encendidos ∩ los del plan), la misma que hace
`CapabilityResolver`. Que fueran dos cuentas distintas es exactamente por lo que
el fallo tardó en verse: las pruebas encendían `core` a mano en esa tabla, así
que su mundo era más generoso que el real.

**Encender un módulo tiene que dar sus permisos a los roles base.** La siembra
es aditiva, así que un rol que ya existe no se vuelve a crear — pero sus
permisos **sí se reconcilian** en cada pasada (`insertOrIgnore` sobre el único
`(rol, permiso)`). Sin eso, el mostrador estrena la caja sin poder cobrar y el
fallo aparece en el peor sitio: con un cliente delante.

**La carta viene paginada** (`catalog.page_size`, 50 por defecto). Cualquier
pantalla que necesite la carta ENTERA —la caja— tiene que seguir las páginas.
Un producto que no está en la cuadrícula es un producto que no se puede vender,
y el cajero no tiene forma de saber que le falta. (El portal no: su `/portal/menu`
devuelve la carta completa, porque el cliente hace scroll, no pasa páginas.)

**Sin `business_hours`, el portal no acepta NI UN pedido.** Un día sin fila
configurada está cerrado, y es el fallo seguro: un pedido de un día que nadie
configuró llega a una cocina apagada. El síntoma engaña —la carta se ve, y pedir
contesta «está cerrado» a cualquier hora—, así que la siembra de demostración
crea el horario junto con el negocio.

**Un estado que depende del negocio se resuelve con SU hora, no con la del
servidor.** `Carbon::now($tenant->timezone)`. Un contenedor en UTC cierra la
arepera de Caracas cuatro horas antes. El huso viaja en `/me` (`tenant.timezone`)
y en `/portal/shop`, para que el frontend feche con él y no con el del navegador.

**Un plazo se manda en SEGUNDOS, no como fecha.** El tablero, la cocina y el
seguimiento del cliente reciben `waitingSeconds` / `expiresInSeconds` calculados
en el servidor: el reloj de una tablet de local casi nunca está bien, y el de un
teléfono en la calle está peor. Con una fecha ISO, «te quedan 20 minutos para
mandar el comprobante» sale mal justo donde más duele — ese número decide si
alguien pierde su pedido. Y nunca negativo: cero significa que el plazo pasó, y
la pantalla dice qué va a ocurrir en vez de enseñar un «-3 min».

**Los pasos del pedido pueden llegar con huecos.** `ready` se marca con
`ready_at`, y una venta que se entrega directamente en el mostrador nunca pasa
por ahí: llegan como `[hecho, hecho, NO, hecho]`. Quien los pinte no puede
suponer que el último `done` es el paso actual —un pedido ya entregado se vería
como si siguiera en la plancha—: terminado lo dice el ÚLTIMO paso, y el avance
son los seguidos desde el principio.

**Entrar en un negocio son TRES cosas, no una**: el parámetro de PostgreSQL
(para RLS), `TenantContext` (para el ámbito global de Eloquent) y olvidar las
capacidades memorizadas. Con sólo la primera, el SQL crudo funciona y Eloquent
devuelve cero filas. Fuera de una petición HTTP —colas, tareas, webhooks— se usa
`TenantSession::within()`, que además **restaura** el negocio anterior en vez de
limpiarlo: un oyente que entra a otro negocio en mitad de una petición dejaba a
esa petición sin contexto, y el síntoma era un 404 sin relación aparente.

**Cachear el «no lo conozco» tiene fecha de caducidad corta.** El resolutor de
webhooks guarda la ausencia sólo diez segundos: si un negocio conecta su canal
justo después de que alguien preguntara por ese número, una hora de caché
negativa lo deja **una hora sin recibir un solo mensaje**, sin ningún error a la
vista.

**Las marcas de tiempo se guardan con precisión de SEGUNDOS.** Dos mensajes del
mismo segundo —el bot contestando— empatan al ordenar por `created_at`. Se
desempata por `id`, que es un uuid7 y lleva el tiempo dentro.

**La super administración entra por otro guard** (`platform`), con su propia
tabla de usuarios y sólo en `admin.dominio`. Estar dentro de un negocio no la
abre, ni al revés: confundirlos es cómo se acaba dando acceso a la facturación
de todos los clientes al empleado de uno.

**`current_period_end` es el único campo que decide** si un negocio está al
día. No hay banderas que alguien tenga que acordarse de mover: hay una fecha y
un trabajo diario que la mira. Ése fue justo el hueco del proyecto anterior —
existía un `plan_expires_at` que no leía nadie.

**Cortar en silencio es el peor fallo de una pantalla.** La cocina y el tablero
de pedidos tienen tope y ordenan de lo más viejo a lo más nuevo, así que
pasarse el tope esconde lo RECIÉN entrado. Las dos respuestas traen
`meta.hidden` y las dos pantallas lo gritan. Cualquier lista con tope debe
hacer lo mismo.

Las que sí se pueden seguir —carta, clientes, negocios de la super
administración— van paginadas con el mismo `meta` (`page`, `lastPage`, `total`,
el tipo `Paged<T>` de `@kombo/api-client`) y con `ListFooter`, que dice «se ven
50 de 693» y trae la siguiente tanda. **Devolver sólo `r.data` desde un
`api/*.ts` es cómo se pierde ese `meta`**: durante meses el panel enseñó 50
productos de 693 porque el servidor mandaba la cuenta y el cliente la tiraba en
una línea.

**Y una prueba nunca busca lo suyo recorriendo una lista paginada.** Usa el
buscador: la que espera encontrar su producto en la primera página deja de pasar
el día en que el negocio tenga cincuenta y uno, y falla lejos de lo que rompió.

**Una venta es un pedido CONFIRMADO y no cancelado**, y esa definición vive en
un solo sitio (`SalesReport::sales()`). Si cada reporte la definiera por su
cuenta, el total del resumen no cuadraría con la suma por canal y nadie sabría
cuál de los dos creer. Y **vendido no es cobrado**: la diferencia es lo que
falta por cobrar, y es de las primeras cosas que un dueño mira.

**Márgenes no hay, y no se inventan.** Calcular ganancia exige costos por
producto, que el sistema no tiene. Enseñar una «ganancia» calculada sobre la
nada sería peor que no enseñarla: alguien tomaría decisiones con ella.

**Un rango calculado en la hora del negocio hay que mandarlo en UTC.** El
constructor de consultas serializa una fecha como `Y-m-d H:i:s` y **tira el
huso**; PostgreSQL lo lee en el huso de la sesión. Un «hoy» de Caracas enviado
sin convertir se corre cuatro horas, así que las ventas de después de las ocho
de la noche dejan de contar como de hoy — y a las once de la mañana todo parece
correcto, que es lo que lo hace difícil de ver.

**Lo que devuelve un endpoint tiene que ser lo que ese endpoint acepta.** Los
horarios volvían como `08:00:00` y el `PUT` exigía `H:i`: el formulario no podía
guardar lo que acababa de leer.

**El catálogo de roles sólo reparte permisos que EXISTEN.** Uno de un módulo que
no se ha construido se filtra al aplicarlo, y ése es el problema: el catálogo
dice que el encargado puede algo, no puede, y nadie se entera hasta que lo
intenta.

**Las fotos de productos van a disco PÚBLICO; los comprobantes, a privado.** No
es un descuido: la foto de una arepa está para que la vea cualquiera que abra el
portal, y un comprobante lleva la cédula y el saldo de quien pagó. Distinta
cosa, distinto sitio. Y las rutas se guardan RELATIVAS (`/storage/...`): una URL
absoluta la armaría `APP_URL`, que es el dominio raíz, y estas imágenes se ven
desde el subdominio de cada negocio.

**Un cuerpo en streaming puede ejecutarse después de que el middleware haya
soltado el negocio.** La exportación vuelve a entrar con `TenantSession::within`
dentro del cierre: sin eso, RLS devuelve cero filas y el archivo sale con la
cabecera y nada más — y un export vacío es peor que un error, porque el negocio
se lo lleva creyendo que ahí están sus pedidos.

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
6. **¿Está escrito tu trabajo en `docs/trabajos/`?** Con lo que descartaste, lo
   que falló por el camino y cómo lo verificaste — que es lo que nadie va a
   poder deducir del diff dentro de seis meses. Y si el porqué de una línea de
   código vive ahí, cita el código donde está la línea.
7. Si ampliaste `RoleCatalog`: ¿corriste `roles:reconciliar`? Si no, el cambio
   no llega a ningún negocio que ya exista ([KMB-0007]).

[KMB-0007]: docs/trabajos/KMB-0007-reconciliar-los-roles-de-los-negocios-que-ya-exist/
