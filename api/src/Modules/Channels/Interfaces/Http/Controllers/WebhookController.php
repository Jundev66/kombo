<?php

declare(strict_types=1);

namespace Modules\Channels\Interfaces\Http\Controllers;

use App\Models\Channels\ChannelAccountModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\Channels\Application\Jobs\ProcessIncomingMessage;
use Modules\Channels\Infrastructure\Services\ChannelFactory;
use Modules\Channels\Infrastructure\Services\ChannelRouter;
use Modules\Channels\Infrastructure\Services\MessageDeduplicator;
use Platform\Tenancy\TenantSession;

/**
 * The door messages come in through. The order of the steps is not incidental:
 *
 *   1. Resolve the tenant — without it there are no credentials to look up.
 *   2. Verify the signature, before anything else; anyone can POST here.
 *   3. Deduplicate, in that order: deduplicating first would let an unsigned
 *      POST burn a legitimate message's id, and the real one would arrive and
 *      be discarded as a repeat. A silent failure, and hard to spot.
 *   4. Answer 200 and enqueue. Meta cuts off at 30 seconds and retries, and
 *      processing inline turns a slow kitchen into a storm.
 */
final class WebhookController
{
    public function __construct(
        private readonly ChannelRouter $router,
        private readonly ChannelFactory $channels,
        private readonly MessageDeduplicator $dedup,
        private readonly TenantSession $session,
    ) {}

    /**
     * Meta's registration check: returns the `hub.challenge` verbatim, after
     * comparing the token in constant time.
     */
    public function verify(Request $request, string $channel): Response
    {
        $externalId = (string) $request->query('external_id', '');
        $tenantId = $this->router->tenantFor($channel, $externalId);

        if ($tenantId === null) {
            return response('', 404);
        }

        $account = $this->accountOf($tenantId, $channel);
        $token = $request->query('hub_verify_token') ?? $request->query('hub.verify_token');

        if ($account === null || ! is_string($token) || ! hash_equals((string) $account->webhook_secret, $token)) {
            return response('', 403);
        }

        return response((string) ($request->query('hub_challenge') ?? $request->query('hub.challenge')));
    }

    public function __invoke(Request $request, string $channel): JsonResponse
    {
        $payload = $request->json()->all();
        $externalId = $this->externalIdOf($channel, $payload);

        $tenantId = $externalId === null ? null : $this->router->tenantFor($channel, $externalId);

        if ($tenantId === null) {
            /*
             * 200 even when we do not know whose it is: Meta retries anything
             * else, and retrying a message for a tenant that no longer exists
             * wastes both ends.
             */
            Log::info('Webhook de un canal que no conocemos', ['channel' => $channel, 'external_id' => $externalId]);

            return response()->json(['ok' => true]);
        }

        $account = $this->accountOf($tenantId, $channel);

        if ($account === null || ! $account->is_active) {
            return response()->json(['ok' => true]);
        }

        $adapter = $this->channels->for($account);

        // 2. The signature, before touching anything else.
        if (! $adapter->verifySignature($request->getContent(), $request->headers->all(), $account->webhook_secret)) {
            Log::warning('Webhook con firma inválida', ['channel' => $channel, 'tenant' => $tenantId]);

            // 403 and not 200: this is not a retry to cut off, it is somebody knocking
            // on a door that is not theirs.
            return response()->json(['message' => 'Firma inválida.'], 403);
        }

        foreach ($adapter->parse($payload) as $message) {
            // 3. Deduplicate, now that the signature is verified.
            if (! $this->dedup->firstTime($tenantId, $channel, $message->externalId)) {
                continue;
            }

            // 4. Onto the queue. Nothing is cooked here.
            ProcessIncomingMessage::dispatch($tenantId, $channel, $message);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * The id of the account this is addressed to.
     *
     * In the controller rather than the adapter because it is needed BEFORE we
     * know which adapter to use: the chicken and egg of a multi-tenant webhook.
     *
     * @param  array<string, mixed>  $payload
     */
    private function externalIdOf(string $channel, array $payload): ?string
    {
        return match ($channel) {
            // Meta puts it inside the change, in `metadata`.
            'whatsapp' => $payload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] ?? null,

            // Telegram sends nothing that identifies the bot, so its webhook carries
            // the account in the address — the one thing Telegram lets you configure
            // per bot.
            'telegram' => request()->route('externalId'),

            default => null,
        };
    }

    private function accountOf(string $tenantId, string $channel): ?ChannelAccountModel
    {
        /*
         * The tenant is entered by hand: there was no subdomain to set it. And
         * with the WHOLE session, not just the PostgreSQL parameter — Eloquent's
         * global scope also needs `TenantContext`, or this query returns zero
         * rows with RLS correctly in place.
         */
        $this->session->enter($tenantId);

        return ChannelAccountModel::where('channel', $channel)->first();
    }
}
