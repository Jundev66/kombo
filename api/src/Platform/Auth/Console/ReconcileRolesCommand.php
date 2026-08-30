<?php

declare(strict_types=1);

namespace Platform\Auth\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Auth\RoleProvisioner;
use Platform\Tenancy\TenantSession;

/**
 * Poner al día los roles base de todos los negocios contra el catálogo.
 *
 * Se corre **después de ampliar `RoleCatalog`**, y es lo que hace que el cambio
 * llegue a alguien. Sin esto, los permisos nuevos sólo los reciben los negocios
 * que se den de alta a partir de hoy: el código se despliega, no falla nada, y
 * el encargado de un negocio de hace seis meses sigue sin poder tocar el
 * horario. Un cambio que no rompe y tampoco hace nada tarda meses en
 * descubrirse.
 *
 * También sirve tras encender un módulo a mano: sus permisos aparecen para los
 * roles que el catálogo dice que los tienen. Sin eso, un mostrador estrena la
 * caja sin poder cobrar, y el fallo sale en el peor sitio —con un cliente
 * delante—.
 *
 * No lleva horario: es una operación de despliegue, no una tarea periódica.
 * Correrla sola cada noche escondería que hace falta correrla.
 */
final class ReconcileRolesCommand extends Command
{
    protected $signature = 'roles:reconciliar {--negocio= : Sólo este subdominio}';

    protected $description = 'Da a los roles base de cada negocio los permisos que les toca según el catálogo.';

    public function handle(RoleProvisioner $provisioner, TenantSession $session): int
    {
        $negocios = DB::table('tenants')
            ->when(
                is_string($this->option('negocio')),
                fn ($query) => $query->where('slug', $this->option('negocio')),
            )
            ->whereNull('deleted_at')
            ->orderBy('slug')
            ->get(['id', 'slug']);

        if ($negocios->isEmpty()) {
            $this->warn('No hay negocios que poner al día.');

            return self::SUCCESS;
        }

        $roles = 0;
        $permisos = 0;

        foreach ($negocios as $negocio) {
            // `within()` y no sólo el parámetro de PostgreSQL: hace falta
            // también el ámbito global de Eloquent, y que al terminar se
            // restaure el negocio anterior en vez de limpiarse.
            $hecho = $session->within(
                (string) $negocio->id,
                fn (): array => $provisioner->reconcile((string) $negocio->id),
            );

            $roles += $hecho['roles'];
            $permisos += $hecho['permissions'];

            if ($hecho['roles'] > 0 || $hecho['permissions'] > 0) {
                $this->line(sprintf(
                    '  %-24s %d rol(es), %d permiso(s)',
                    $negocio->slug,
                    $hecho['roles'],
                    $hecho['permissions'],
                ));
            }
        }

        // Se dice el total aunque sea cero: «0 permisos nuevos» es la respuesta
        // correcta a la segunda pasada, y sin imprimirla parece que no corrió.
        $this->info(sprintf(
            '%d negocio(s) revisados · %d rol(es) y %d permiso(s) nuevos.',
            $negocios->count(),
            $roles,
            $permisos,
        ));

        return self::SUCCESS;
    }
}
