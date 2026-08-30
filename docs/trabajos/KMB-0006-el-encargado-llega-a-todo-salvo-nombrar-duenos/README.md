---
codigo: KMB-0006
titulo: El encargado llega a todo salvo nombrar dueños
tipo: funcionalidad
estado: hecho
fecha: 2026-08-29
toca: [api/src/Platform/Auth]
relacionados: [KMB-0005, KMB-0007]
---

# KMB-0006 · El encargado llega a todo salvo nombrar dueños

## Por qué

El encargado es quien lleva el local cuando el dueño no está, y no podía
configurar el **horario** — que es justo aquello sin lo cual el portal no acepta
ni un pedido. Tampoco la **tasa del día**, de la que cuelga cada precio en
bolívares, ni el **bot**, ni el **equipo**.

Lo del equipo estaba quitado a propósito, con su comentario: «quien puede crear
usuarios puede crearse una cuenta de dueño». El razonamiento era correcto y la
solución no: quitarle el permiso también le impedía lo legítimo, que es dar de
alta al cocinero nuevo un sábado por la tarde.

## Qué se hizo

`manager` gana `settings.manage`, `channels.view`, `audit.view`,
`users.manage`, `delivery.view_own` y `delivery.mark_delivered`.

Y el agujero se tapa **donde de verdad se decide**, en `TeamController`:

| Guarda | Dónde | Qué impide |
|---|---|---|
| `assertCanAssignRole()` | `store()`, `update()` | Que un no-dueño asigne un rol con `is_owner` |
| `assertCanTouchOwner()` | `update()`, `destroy()` | Que un no-dueño edite o dé de baja a un dueño |
| `availableRoles()` filtra | `index()` | Que el desplegable ofrezca lo que el servidor va a rechazar |

**La segunda no es celo.** `update()` acepta `password`: sin ella, al encargado
le bastaba con cambiarle la clave al dueño y entrar como él. No hacía falta
ascenderse a nada, así que la primera guarda sola era decorativa.

## Qué se descartó, y por qué

**Dárselo todo, incluido nombrar dueños.** Es lo más simple de explicar. Se
descartó porque un encargado podría darse a sí mismo el negocio, y eso no se
deshace desde dentro.

**Un ajuste por negocio para que cada dueño decida.** Más flexible, y una
pantalla más que configurar con un valor por defecto que igual había que elegir
de todas formas.

**Dejar el equipo fuera y darle sólo la operación diaria.** Cerraba el hueco que
duele —el horario— sin tocar el reparto de poder. Se descartó porque no resolvía
el sábado por la tarde.

## Qué falló por el camino

El encargado seguía sin ver las **entregas**: podía configurar zonas y tarifas
(`delivery.manage`) y no abrir el tablero (`delivery.view_own`). Se vio al
comprobar el menú en el navegador, no leyendo el catálogo. Es la misma
incoherencia que el horario, y se cerró en la misma pasada.

## Cómo se verificó

```bash
make test    # TeamTest: seis casos nuevos
```

Los que importan: el encargado **suma gente**, no puede **crear** un dueño, no
puede **ascender** a nadie a dueño, no le **cambia la contraseña** al dueño, no
lo **da de baja**, y su desplegable de roles no ofrece «Dueño».

Y a mano contra el entorno: José entra al panel y ve Horario, Tasa, WhatsApp y
Equipo; el `POST` con `role_code: owner` responde 422 y el `PATCH` sobre María,
403.

## Lo que quedó fuera

`audit.view` se concede pero **no hay pantalla de bitácora** en el panel. Es un
permiso que existe en el servidor y todavía no se puede ejercer desde la
interfaz.
