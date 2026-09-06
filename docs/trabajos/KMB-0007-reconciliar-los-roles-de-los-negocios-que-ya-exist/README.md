---
codigo: KMB-0007
titulo: Reconciliar los roles de los negocios que ya existen
tipo: arreglo
estado: hecho
fecha: 2026-08-29
toca: [api/src/Platform/Auth/RoleProvisioner.php, api/src/Platform/Auth/Console, api/tests/Pest.php]
relacionados: [KMB-0002, KMB-0006]
---

# KMB-0007 · Reconciliar los roles de los negocios que ya existen

## Por qué

`role_permissions` **sólo se escribía al dar de alta un negocio**
(`OnboardTenant`). Ampliar `RoleCatalog` —darle el horario al encargado, por
ejemplo ([KMB-0006])— servía a los negocios que se registraran a partir de ese
despliegue y a nadie más.

El código salía, no fallaba nada, y el encargado de un local de hace seis meses
seguía sin poder. **Un cambio que no rompe y tampoco hace nada tarda meses en
descubrirse**, y cuando se descubre nadie lo relaciona con el despliegue que lo
causó.

## Qué se hizo

La siembra vive ahora en un solo sitio, `RoleProvisioner::reconcile()`, que usan
las tres: el alta de un negocio, el seeder de demostración y los helpers de
prueba. Y un comando la aplica a los que ya existen:

```bash
php artisan roles:reconcile              # todos
php artisan roles:reconcile --tenant=elsazon
```

Es idempotente por construcción: se apoya en los dos únicos que ya declaraba el
esquema —`(tenant_id, code)` en `roles` y `(tenant_id, role_id, permission)` en
`role_permissions`—, así que correrlo dos veces no duplica una fila.

**No lleva horario.** Es una operación de despliegue, no una tarea periódica:
correrla sola cada noche escondería que hace falta correrla.

## Qué se descartó, y por qué

**Una migración.** Habría funcionado esta vez y no la siguiente: el problema se
repite cada vez que alguien amplía el catálogo, y una migración por ampliación
es un archivo por cambio de dos líneas.

**Reconciliar en cada arranque de la aplicación.** Tentador y peligroso: son
escrituras en `role_permissions` de todos los negocios en el camino crítico de
una petición.

## Qué falló por el camino

**El fallo grande, que estaba ahí desde el principio y no se veía.** Las cuatro
copias de esta lógica leían los módulos activos de `tenant_modules` a secas. Y
**el núcleo nunca tiene fila ahí** —no depende del plan y no se apaga—, así que
`settings.manage`, `users.manage` y `audit.view` **no llegaban a ningún rol que
no fuera el dueño**. El síntoma era un encargado que no podía tocar el horario
sin ningún error que lo explicara.

La cuenta correcta es `coreCodes()` + (encendidos ∩ los del plan), la misma que
hace `CapabilityResolver`. Que fueran dos cuentas distintas es exactamente por
lo que tardó en verse: **los helpers de prueba encendían `core` a mano en esa
tabla**, así que el mundo de las pruebas era más generoso que el real — la peor
dirección en la que pueden diferir.

**Y una afirmación mía era falsa.** Escribí que la reconciliación «no devuelve
un permiso que el dueño quitó a mano». `insertOrIgnore` sí lo repone. Se corrigió
el comentario en vez de la prueba: los roles base son `is_system` y no se editan
desde ninguna pantalla, así que restaurarlos es lo correcto. El día que un dueño
pueda quitar un permiso suelto, habrá que guardar esa decisión en algún sitio —
un borrado no es una decisión, es una fila que no está.

## Cómo se verificó

```bash
make test    # RolesTest: 8 casos
php artisan roles:reconcile
```

La prueba que más vale es la del núcleo: los permisos de `core` llegan al
encargado **aunque `core` no esté en `tenant_modules`**. Es el guardián del
fallo de arriba.

Contra el entorno real: 59 negocios revisados, 383 permisos que faltaban. La
segunda pasada, cero.

## Lo que quedó fuera

No hay nada que **recuerde** correr el comando después de ampliar el catálogo.
Está en `AGENTS.md` como regla, pero si alguien la olvida el sistema no se queja
— vuelve a ser un cambio que no falla y tampoco hace nada.
