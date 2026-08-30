---
codigo: KMB-0005
titulo: El dueño supervisa su propia caja con la sesión que ya tiene
tipo: funcionalidad
estado: hecho
fecha: 2026-08-29
toca: [web/packages/shell, web/apps/caja, web/apps/kds]
relacionados: [KMB-0006, KMB-0010]
---

# KMB-0005 · El dueño supervisa su propia caja con la sesión que ya tiene

## Por qué

El dueño acababa de entrar al panel con su contraseña y, para mirar su propia
caja, la pantalla le pedía **dar de alta el aparato** con correo y contraseña y
después **teclear un PIN**. Tres veces demostrando quién es.

Al mirarlo se vio que el problema no era de permisos: `RoleCatalog` ya le da
`['*']`, y `RequirePermission.php` deja pasar las sesiones por cookie **a
propósito**. El servidor ya lo permitía. Quien lo impedía era una línea del
frontend: `caja/src/App.tsx` exigía un token de estación en `localStorage`.

Y había algo peor debajo. **Sanctum prefiere la cookie de sesión al token**, así
que en una máquina donde alguien dejó el panel abierto, el cajero tecleaba su
PIN y **todo se ejecutaba a nombre del dueño sin que nada lo dijera**. El
síntoma era un permiso que debería faltar y no faltaba.

## Qué se hizo

Se invirtió el arranque de la caja y la cocina: **primero `/me`, y quien manda
ahí es quien opera**. De eso salen tres formas de entrar (`useDoorway`):

| Modo | Cuándo | Qué se ve |
|---|---|---|
| `supervision` | Hay sesión de navegador y no hay turno | Banda ámbar «Supervisando · María (Dueño)» y «Volver al panel» |
| `shift` | Hay turno en esta máquina | Lo de siempre, con el nombre del operador en la cabecera |
| `gate` | Ninguna de las dos | `TerminalGate`: alta del aparato y PIN |

En los dos primeros, la cabecera enseña **quien el servidor dice que opera**, no
quien tecleó el PIN. La trampa de Sanctum sigue existiendo; lo que cambió es que
ahora se ve.

## Qué se descartó, y por qué

**Una vista de supervisión de sólo lectura dentro del panel.** Más segura, y no
resuelve el caso real: el dueño que quiere cobrar un pedido porque hay cola.

**Pedirle el PIN igual, saltándose sólo el alta del aparato.** Deja rastro de
quién vendió sin depender de la cookie. Se descartó porque el rastro ya lo da
`/me`: la venta va a nombre de quien el servidor autentica, con PIN o sin él.

## Qué falló por el camino

**El color de la banda no existía.** Se escribió `bg-bad-600` y el tema sólo
define los tonos 50, 500 y 700, así que Tailwind no emitió nada y la banda salió
gris. Acabó en `bg-warn-500` con `text-ink-900` — ámbar y no rojo, porque no es
un fallo sino un aviso, y es el par que ya usa la cocina y se lee sobre el tema
claro y el oscuro.

**Dos regiones `role="status"` competían** —la banda y el spinner de «cargando la
carta»— y la prueba de navegador no podía señalar una. Se arregló donde tocaba:
dándole nombre a la banda (`aria-label="Supervisión"`), que además la hace
distinguible para un lector de pantalla.

**Tres pruebas E2E de cocina estaban dentro de la trampa.** Decían «otra
pantalla, otra sesión» y no cerraban la del panel, así que tecleaban el PIN de
Carlos y operaban como María. Pasaban en verde. Ahora hacen `signOut()` antes, y
el comentario es cierto.

## Cómo se verificó

```bash
make check
./e2e/run.sh tests/caja.spec.ts
```

Dos casos nuevos: el dueño entra desde el panel y **vende** sin alta ni PIN, y
«Volver al panel» no cierra su sesión. Y se comprobó a mano que el turno normal
—sin sesión, con alta y PIN— sigue igual y ahora dice «Caja · Ana».

## Lo que quedó fuera

El riesgo de que el dueño abra supervisión en la PC del mostrador y se vaya. La
banda es la mitigación y es estrictamente mejor que el silencio anterior, pero
nada cierra esa sesión sola.
