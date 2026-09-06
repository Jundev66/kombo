<?php

declare(strict_types=1);

namespace Platform\Auth\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Auth\RoleProvisioner;
use Platform\Tenancy\TenantSession;

/**
 * Brings every tenant's base roles up to date with the catalog.
 *
 * Run after widening `RoleCatalog`, or after switching a module on by hand.
 * Without it, new permissions only reach tenants created from today on.
 */
final class ReconcileRolesCommand extends Command
{
    protected $signature = 'roles:reconcile {--tenant= : Sólo este subdominio}';

    protected $description = 'Da a los roles base de cada negocio los permisos que les toca según el catálogo.';

    public function handle(RoleProvisioner $provisioner, TenantSession $session): int
    {
        $tenants = DB::table('tenants')
            ->when(
                is_string($this->option('tenant')),
                fn ($query) => $query->where('slug', $this->option('tenant')),
            )
            ->whereNull('deleted_at')
            ->orderBy('slug')
            ->get(['id', 'slug']);

        if ($tenants->isEmpty()) {
            $this->warn('No hay negocios que poner al día.');

            return self::SUCCESS;
        }

        $roles = 0;
        $permissions = 0;

        foreach ($tenants as $tenant) {
            // `within()` rather than just the PostgreSQL parameter: Eloquent's global
            // scope needs it too, and the previous tenant has to be restored after.
            $done = $session->within(
                (string) $tenant->id,
                fn (): array => $provisioner->reconcile((string) $tenant->id),
            );

            $roles += $done['roles'];
            $permissions += $done['permissions'];

            if ($done['roles'] > 0 || $done['permissions'] > 0) {
                $this->line(sprintf(
                    '  %-24s %d rol(es), %d permiso(s)',
                    $tenant->slug,
                    $done['roles'],
                    $done['permissions'],
                ));
            }
        }

        // The total is printed even when zero, so a second pass does not look
        $this->info(sprintf(
            '%d negocio(s) revisados · %d rol(es) y %d permiso(s) nuevos.',
            $tenants->count(),
            $roles,
            $permissions,
        ));

        return self::SUCCESS;
    }
}
