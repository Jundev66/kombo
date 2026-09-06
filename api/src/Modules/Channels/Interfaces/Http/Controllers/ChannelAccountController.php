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
 * Connecting and disconnecting channels.
 *
 * What gets stored is a token that can write to every customer in the tenant's
 * name, so: it is never returned (not masked, not the last four digits), it is
 * encrypted in the database, and the audit log records who changed it.
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

                    // The address to paste into Meta's console or hand to Telegram, computed
                    // here so nobody assembles it by hand and gets a character wrong.
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

            // WhatsApp uses `access_token`, Telegram `bot_token`. Either is accepted
            // and the adapter knows which is its own.
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
             * The route is written in the SAME transaction: an account with no
             * route receives no messages, and a route with no account points at
             * a tenant that cannot answer.
             */
            $this->router->register($channel, $data['external_id'], $tenantId);

            return $account;
        });

        $this->audit->record(
            action: 'channels.connected',
            entityType: 'channel_account',
            entityId: (string) $account->id,
            // The token does NOT go into the audit log. What matters is who changed it.
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
     * Disconnecting: switched off, not deleted. The last few months'
     * conversations still have to be readable, and reconnecting is pasting the
     * token again rather than rebuilding history.
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

        // Telegram carries the account in the address; WhatsApp brings it in the
        // body, so its address is the same for everybody.
        return $channel === 'telegram' && $externalId !== null
            ? "{$base}/webhooks/telegram/{$externalId}"
            : "{$base}/webhooks/{$channel}";
    }
}
