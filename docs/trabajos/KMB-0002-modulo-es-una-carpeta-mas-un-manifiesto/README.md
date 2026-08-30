---
codigo: KMB-0002
titulo: Un módulo es una carpeta más un manifiesto
tipo: decision
estado: hecho
fecha: 2026-08-25
toca: [api/src/Platform/Modules, api/src/Modules, api/config/modules.php]
relacionados: [KMB-0001, KMB-0003]
---

# KMB-0002 · Un módulo es una carpeta más un manifiesto

## Por qué

La regla de oro del proyecto es que **nunca se escribe código para un solo
negocio**. Para que eso sea sostenible hace falta que encender una capacidad
para un cliente concreto no sea un despliegue, sino un dato.

Sin eso, «el cliente X necesita la caja» acaba siendo un `if` con el nombre del
cliente dentro. Y a partir del tercero, el sistema es inmantenible.

## Qué se hizo

Cada módulo declara un `ModuleManifest`: su código, sus permisos, sus ajustes y
sus rutas. De ahí sale todo:

- **Activar un módulo es una fila en `tenant_modules`**, sin desplegar.
- Sus permisos aparecen y desaparecen con él.
- El panel genera su formulario de configuración solo, a partir de los
  `Setting` del manifiesto.
- **Las rutas no van en `routes/api.php`**: las declara el manifiesto y las
  carga `PlatformServiceProvider` bajo el middleware `module:{codigo}`.

Y la distinción de códigos de respuesta, que no es cosmética:

| Situación | Respuesta | Por qué |
|---|---|---|
| Módulo apagado | **404** | Que no exista para ese negocio es información sobre su contrato |
| Permiso que falta | **403** | Que el usuario no pueda es decisión de su propio dueño, y se le dice claro |

## Qué se descartó, y por qué

**Un paquete de Composer por módulo.** Da un aislamiento más fuerte y un
`composer.json` por vertical. Se descartó porque el precio es un flujo de
publicación por cada cambio de dos líneas, en un producto que todavía cambia
todos los días.

**Feature flags de una librería externa.** Resuelven el encendido pero no el
resto: los permisos, los ajustes, las rutas y el formulario del panel seguirían
escritos a mano en cuatro sitios que se desincronizan.

## Qué falló por el camino

**Encender un módulo tiene que dar sus permisos a los roles base.** La primera
versión creaba los roles y no reconciliaba sus permisos, así que un mostrador
estrenaba la caja sin poder cobrar — y el fallo aparecía en el peor sitio, con
un cliente delante. Ver [KMB-0007], que es donde esto se terminó de cerrar.

**El orden de los proveedores importa.** `PlatformServiceProvider` primero y los
verticales al final: sustituyen enlaces del contenedor, y gana el último que
registra.

## Cómo se verificó

```bash
make test-arch    # BoundariesTest: un módulo no importa entidades de otro
make test         # que un módulo apagado responde 404 y no 403
```

`BoundariesTest` es el que sostiene la separación: el dominio no importa el
framework, `Platform` no conoce `Modules`, y un módulo no importa entidades de
otro. Importar la entidad ajena sería poder invocar sus reglas desde fuera del
módulo que las defiende.

## Lo que quedó fuera

No hay forma de que un negocio instale un módulo de terceros. Se decidió que
hasta no tener un segundo desarrollador de módulos, un sistema de extensiones
es infraestructura para un problema que no existe.
