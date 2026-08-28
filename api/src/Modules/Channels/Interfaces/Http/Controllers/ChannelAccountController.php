<?php

declare(strict_types=1);

namespace Modules\Channels\Interfaces\Http\Controllers;

use App\Models\Channels\ChannelAccountModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Channels\Infrastructure\Services\ChannelFactory;
use Modules\Channels\Infrastructure\Services\ChannelRouter;
use Modules\Channels\Infrastructure\Services\PortalLink;
use Platform\Audit\AuditLogger;
use Platform\Tenancy\TenantContext;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Conectar y desconectar canales.
 *
 * Lo que se guarda aquí es un token con el que se puede escribir a todos los
 * clientes del negocio en su nombre, así que:
 *
 *   - **Nunca se devuelve.** Ni enmascarado, ni los últimos cuatro dígitos. La
 *     pantalla enseña si hay uno puesto y cuándo se usó por última vez; para
 *     cambiarlo, se pega otro.
 *   - **Se cifra en la base**, con el cast del modelo.
 *   - **Queda en la bitácora** quién lo cambió y cuándo.
 */
final class ChannelAccountController
{
    public function __construct(
        private readonly ChannelFactory $channels,
        private readonly ChannelRouter $router,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public function index(): JsonResponse
    {
        $accounts = ChannelAccountModel::all()->keyBy('channel');

        return response()->json([
            'data' => array_map(function (string $channel) use ($accounts): array {
                $account = $accounts->get($channel);

                return [
                    'channel' => $channel,
                    'connected' => $account !== null,
                    'isActive' => (bool) $account?->is_active,
                    'label' => $account?->label,
                    'externalId' => $account?->external_id,
                    'lastMessageAt' => $account?->last_message_at?->toAtomString(),

                    // La dirección que hay que pegar en la consola de Meta o
                    // darle a Telegram. Se calcula aquí para que nadie tenga
                    // que armarla a mano y equivocarse en un carácter.
                    'webhookUrl' => $this->webhookUrl($channel, $account?->external_id),
                ];
            }, $this->channels->available()),
        ]);
    }

    public function save(Request $request, string $channel): JsonResponse
    {
        if (! in_array($channel, $this->channels->available(), true)) {
            throw new NotFoundHttpException('Ese canal no existe.');
        }

        $data = $request->validate([
            'external_id' => ['required', 'string', 'max:120'],
            'label' => ['nullable', 'string', 'max:80'],
            'webhook_secret' => ['required', 'string', 'min:8', 'max:200'],

            // WhatsApp usa `access_token`; Telegram, `bot_token`. Se acepta
            // cualquiera de los dos y el adaptador sabe cuál es el suyo.
            'credentials' => ['required', 'array'],
            'credentials.access_token' => ['nullable', 'string', 'max:500'],
            'credentials.bot_token' => ['nullable', 'string', 'max:500'],
        ]);

        $tenantId = $this->context->id();

        $account = DB::transaction(function () use ($channel, $data, $tenantId): ChannelAccountModel {
            $account = ChannelAccountModel::updateOrCreate(
                ['channel' => $channel],
                [
                    'external_id' => $data['external_id'],
                    'label' => $data['label'] ?? null,
                    'webhook_secret' => $data['webhook_secret'],
                    'credentials' => array_filter($data['credentials']),
                    'is_active' => true,
                ],
            );

            /*
             * La ruta se escribe en la MISMA transacción.
             *
             * Son dos tablas que no pueden quedar desparejas: una cuenta sin
             * ruta no recibe mensajes, y una ruta sin cuenta apunta a un
             * negocio que no sabe contestar.
             */
            $this->router->register($channel, $data['external_id'], $tenantId);

            return $account;
        });

        $this->audit->record(
            action: 'channels.connected',
            entityType: 'channel_account',
            entityId: (string) $account->id,
            // El token NO va a la bitácora. Lo que importa es quién lo cambió.
            after: ['channel' => $channel, 'external_id' => $data['external_id']],
        );

        return response()->json([
            'data' => [
                'channel' => $channel,
                'connected' => true,
                'webhookUrl' => $this->webhookUrl($channel, $data['external_id']),
            ],
        ]);
    }

    /**
     * Desconectar: se apaga, no se borra.
     *
     * Las conversaciones de los últimos meses siguen ahí y tienen que poder
     * leerse. Y volver a conectar es pegar el token otra vez, no reconstruir
     * el historial.
     */
    public function disconnect(string $channel): JsonResponse
    {
        $account = ChannelAccountModel::where('channel', $channel)->first()
            ?? throw new NotFoundHttpException('Ese canal no está conectado.');

        $account->update(['is_active' => false]);
        $this->router->forget($channel, (string) $account->external_id);

        DB::table('channel_routes')
            ->where('channel', $channel)
            ->where('external_id', $account->external_id)
            ->update(['is_active' => false, 'updated_at' => now()]);

        $this->audit->record(
            action: 'channels.disconnected',
            entityType: 'channel_account',
            entityId: (string) $account->id,
            after: ['channel' => $channel],
        );

        return response()->json(status: 204);
    }

    private function webhookUrl(string $channel, ?string $externalId): string
    {
        $base = PortalLink::forTenant($this->context->current()->slug, '');

        // Telegram lleva la cuenta en la dirección; WhatsApp la trae dentro del
        // cuerpo, así que su dirección es la misma para todos.
        return $channel === 'telegram' && $externalId !== null
            ? "{$base}/webhooks/telegram/{$externalId}"
            : "{$base}/webhooks/{$channel}";
    }
}
