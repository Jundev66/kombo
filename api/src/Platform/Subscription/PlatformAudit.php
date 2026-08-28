<?php

declare(strict_types=1);

namespace Platform\Subscription;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lo que hace un administrador de plataforma, escrito donde se pueda leer.
 *
 * Es una bitácora **aparte** de la de cada negocio. No es burocracia: aquí van
 * las cosas que hacemos NOSOTROS en casa de un cliente —suspenderlo, cambiarle
 * el plan, mirar sus datos—, y esas tienen que poder consultarse cuando el
 * cliente pregunte «¿quién tocó esto?».
 *
 * Se guarda el NOMBRE además del identificador: el día que un administrador
 * deje la empresa y se borre su cuenta, el registro tiene que seguir diciendo
 * quién fue.
 */
final class PlatformAudit
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function record(string $action, ?string $tenantId = null, array $details = []): void
    {
        $user = auth('platform')->user();

        DB::table('platform_audit_log')->insert([
            'id' => (string) Str::uuid7(),
            'platform_user_id' => $user?->getKey(),
            'platform_user_name' => $user?->name,
            'action' => $action,
            'tenant_id' => $tenantId,
            'details' => json_encode($details),
            'ip' => request()->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * Lo que se hizo en casa de un negocio, para poder enseñárselo.
     *
     * @return list<array<string, mixed>>
     */
    public function forTenant(string $tenantId, int $limit = 50): array
    {
        return DB::table('platform_audit_log')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                'action' => $row->action,
                'by' => $row->platform_user_name,
                'at' => $row->created_at,
                'details' => json_decode((string) $row->details, true),
            ])
            ->all();
    }

    /** El origen de la petición, para dejarlo escrito tal cual llegó. */
    public static function ipOf(Request $request): ?string
    {
        return $request->ip();
    }
}
