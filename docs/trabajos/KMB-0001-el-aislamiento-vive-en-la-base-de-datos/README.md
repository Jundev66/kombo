---
codigo: KMB-0001
titulo: El aislamiento vive en la base de datos, no en el código
tipo: decision
estado: hecho
fecha: 2026-08-25
toca: [api/src/Platform/Tenancy, api/tests/Isolation, docker/postgres]
relacionados: [KMB-0002]
---

# KMB-0001 · El aislamiento vive en la base de datos, no en el código

## Por qué

Un solo despliegue atiende a todos los negocios. Que la arepera de la esquina
no vea los pedidos de la pizzería de enfrente no puede depender de que cada
consulta se acuerde de poner `where tenant_id = ...`, porque tarde o temprano
una no se acuerda — y el fallo no se ve: la pantalla enseña datos, sólo que de
otro.

Éste era el hueco del proyecto anterior. Filtrar en el código funciona hasta el
día en que alguien escribe una consulta cruda, un `join` a una tabla que se
olvidó del ámbito, o un `count()` para un panel de administración.

## Qué se hizo

**Row Level Security de PostgreSQL**, con dos usuarios de base de datos:

- `kombo_owner` — dueño del esquema, superusuario, **sólo** para migraciones.
- `kombo_app` — con el que conecta la aplicación, **sin `BYPASSRLS`**.

La política de cada tabla compara contra
`nullif(current_setting('app.tenant_id', true), '')`. Sin contexto puesto, la
comparación da `null` y la tabla devuelve **cero filas**.

`TenantSchema::create()` es la única forma de crear una tabla de negocio:
aporta `tenant_id`, RLS activado **y forzado**, la política, y las claves
foráneas compuestas `(tenant_id, columna) → (tenant_id, id)` — que son lo que
impide meter en el pedido de un negocio el producto de otro.

## Qué se descartó, y por qué

**Una base de datos por negocio.** Aísla perfecto y es lo que muchos hacen. Se
descartó por el coste de operación: cien clientes son cien bases que migrar,
respaldar y vigilar, y una migración que falla en la número setenta deja el
sistema partido en dos versiones.

**Un ámbito global de Eloquent y nada más.** Es lo que hacía el proyecto
anterior. Cubre el 95 % de las consultas y el 5 % restante es exactamente donde
está el problema: SQL crudo, `DB::table()`, agregados. Se mantiene —hace falta
para que Eloquent devuelva lo correcto— pero **encima** de RLS, no en su lugar.

## Qué falló por el camino

**La suite corría como el dueño del esquema**, que se salta RLS. Las pruebas de
aislamiento pasaban en verde con la política completamente rota. Ahora corre
como `kombo_app`; si algún día alguien la cambia, esas pruebas dejan de
significar nada aunque sigan verdes.

**Entrar en un negocio son TRES cosas, no una**: el parámetro de PostgreSQL
(para RLS), `TenantContext` (para el ámbito de Eloquent) y olvidar las
capacidades memorizadas. Con sólo la primera, el SQL crudo funciona y Eloquent
devuelve cero filas — un síntoma que no apunta a ninguna parte.

## Cómo se verificó

```bash
make test-isolation    # 49 pruebas: ninguna vía deja ver lo de otro negocio
make test-arch         # SchemaGuardTest: toda tabla de negocio con RLS y FK compuestas
```

`SchemaGuardTest` recorre el esquema real de PostgreSQL, no una lista escrita a
mano: una tabla nueva sin RLS rompe el build aunque nadie se acuerde de añadirla
a ningún sitio.

## Lo que quedó fuera

El resolutor de negocios cachea en Redis, y cualquier operación que cambie el
identificador de un negocio —recrear la base, restaurar un respaldo— tiene que
invalidar esa caché. Está documentado como trampa en `AGENTS.md`, pero no hay
nada que lo impida automáticamente.
