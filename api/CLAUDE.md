# `api/` · Laravel 13 · PHP 8.5

Lee antes el `CLAUDE.md` de la raíz. Esto es lo específico del backend.

## Las cuatro capas

```
src/Modules/<Modulo>/
  <Modulo>Module.php          EL MANIFIESTO: código, permisos, ajustes, rutas
  <Modulo>ServiceProvider.php Enlaza puertos y registra oyentes

  Domain/                     PHP puro. CERO framework. Lo vigila BoundariesTest.
    Entities/  ValueObjects/  Events/  Ports/  Exceptions/

  Application/                Orquesta. Aquí sí puede aparecer el framework.
    UseCases/  Queries/  DTOs/  Listeners/
    Contracts/                Los puertos que este módulo PUBLICA a los demás

  Infrastructure/
    Persistence/  Services/  Mappers/  Migrations/

  Interfaces/
    Http/{Controllers,Requests,Resources,Routes}
```

Los modelos Eloquent **no** viven en el módulo: van en `app/Models/<Modulo>/`.
Son infraestructura de Laravel, y el dominio nunca los ve.

## Las dos formas de que dos módulos se hablen. Sólo dos.

| Necesitas | Usa |
|---|---|
| Saber algo **ahora** | Un puerto en `Application/Contracts/` del que lo publica, y un DTO |
| Reaccionar a algo **que ya pasó** | Un evento de dominio |

Importar la entidad de otro módulo es poder invocar sus reglas desde fuera del
módulo que las defiende. `BoundariesTest` lo impide.

Y el DTO **no es la entidad**: si `Orders` recibiera la entidad `Product`,
podría cambiarle el precio. Recibe un `ProductSnapshot` de sólo lectura.

> **Los identificadores vienen del cliente; los precios NO.** La caja manda qué
> producto y cuántos; el importe se recalcula siempre del catálogo. Hay una
> prueba que lo verifica.

## Cómo se agrega un módulo

1. `src/Modules/<Nombre>/<Nombre>Module.php extends ModuleManifest`.
2. Las cuatro capas, con `Domain/` limpio.
3. Su `ServiceProvider` en `bootstrap/providers.php` — **el orden importa**:
   `PlatformServiceProvider` primero, los verticales al final (sustituyen
   enlaces del contenedor, y gana el último que registra).
4. Una línea en `config/modules.php`.

**`routes/api.php` no se toca.** Las rutas las declara el manifiesto.

## Tocar el esquema

Siempre con `Platform\Tenancy\Database\TenantSchema`:

```php
TenantSchema::create('orders', function (Blueprint $table) {
    $table->string('code');
    TenantSchema::references($table, 'customer_id', 'customers', nullable: true);
    TenantSchema::index($table, ['status', 'placed_at'], 'idx_orders_tenant_status_placed');
    TenantSchema::uniquePerTenant($table, ['code'], 'uq_orders_tenant_code');
});
```

Aporta solo: `id`, `tenant_id`, marcas de tiempo, `unique(tenant_id, id)` y RLS
activado, forzado y con política. `references()` crea **siempre** la FK
compuesta `(tenant_id, columna) → (tenant_id, id)`, que es lo que impide meter
en el pedido de un negocio el producto de otro.

Si una tabla no lleva `tenant_id` a propósito, va declarada en
`TenantSchema::PLATFORM_TABLES` con su razón. `SchemaGuardTest` no acepta una
tercera opción.

## Qué prueba escribir

| Qué cambiaste | Dónde va la prueba |
|---|---|
| Una regla de negocio, un value object, una máquina de estados | `tests/Unit/` — PHP puro, sin arrancar Laravel |
| Un endpoint, un permiso, un límite de plan | `tests/Feature/` |
| Cualquier cosa que toque el esquema o las consultas | `tests/Isolation/` |
| Un límite del diseño | `tests/Architecture/` |

Los nombres describen **comportamiento de negocio, en español**: «el cajero
puede iniciar una anulación pero no ejecutarla», no «testVoidPermission».

## Migraciones y usuarios de base de datos

```bash
php artisan migrate --database=pgsql_owner   # SIEMPRE como dueño del esquema
```

La aplicación conecta como `kombo_app`, que no puede crear tablas ni saltarse
RLS. Es deliberado.

**Cuidado con los seeders:** corren como el dueño, que se salta RLS. Una
consulta que DECIDE algo dentro de un seeder tiene que filtrar por `tenant_id`
a mano — el aislamiento ambiental no está puesto ahí.
