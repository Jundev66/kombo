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
 * La puerta por donde entran los mensajes.
 *
 * El orden de lo que pasa aquí no es casual — **cada paso está antes que el
 * siguiente por una razón concreta**:
 *
 *   1. **Resolver el negocio.** Sin esto no hay credenciales que consultar, y
 *      sin credenciales no hay firma que comprobar.
 *   2. **Comprobar la firma.** Antes que nada más. Cualquiera puede hacer un
 *      POST aquí.
 *   3. **Deduplicar.** Y en este orden, no al revés: si se deduplicara antes de
 *      firmar, un POST sin firma con el identificador de un mensaje legítimo lo
 *      quemaría, y el de verdad llegaría y se descartaría por repetido. Es un
 *      fallo silencioso y muy difícil de ver.
 *   4. **Responder 200 y encolar.** Meta corta a los 30 segundos y reintenta.
 *      Procesar en línea convierte una cocina lenta en una tormenta de
 *      mensajes repetidos.
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
     * La comprobación de alta de Meta: devuelve el `hub.challenge` tal cual.
     *
     * Se compara el token con el que el negocio configuró, en tiempo constante.
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
             * 200 aunque no se sepa de quién es.
             *
             * Meta reintenta cualquier cosa que no sea 200, y reintentar un
             * mensaje para un negocio que ya no existe es gastar dos lados. Se
             * registra y se cierra.
             */
            Log::info('Webhook de un canal que no conocemos', ['channel' => $channel, 'external_id' => $externalId]);

            return response()->json(['ok' => true]);
        }

        $account = $this->accountOf($tenantId, $channel);

        if ($account === null || ! $account->is_active) {
            return response()->json(['ok' => true]);
        }

        $adapter = $this->channels->for($account);

        // ── 2. La firma, ANTES de tocar nada más.
        if (! $adapter->verifySignature($request->getContent(), $request->headers->all(), $account->webhook_secret)) {
            Log::warning('Webhook con firma inválida', ['channel' => $channel, 'tenant' => $tenantId]);

            // 403 y no 200: esto no es un reintento que haya que cortar, es
            // alguien llamando a una puerta que no es suya.
            return response()->json(['message' => 'Firma inválida.'], 403);
        }

        foreach ($adapter->parse($payload) as $message) {
            // ── 3. Deduplicar, ya con la firma comprobada.
            if (! $this->dedup->firstTime($tenantId, $channel, $message->externalId)) {
                continue;
            }

            // ── 4. A la cola. Aquí no se cocina nada.
            ProcessIncomingMessage::dispatch($tenantId, $channel, $message);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * El identificador de la cuenta a la que va dirigido esto.
     *
     * Vive en el controlador y no en el adaptador porque hace falta **antes**
     * de saber qué adaptador usar: es el huevo y la gallina de un webhook
     * multi-negocio.
     *
     * @param  array<string, mixed>  $payload
     */
    private function externalIdOf(string $channel, array $payload): ?string
    {
        return match ($channel) {
            // Meta lo pone dentro del cambio, en `metadata`.
            'whatsapp' => $payload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] ?? null,

            // Telegram no manda nada que identifique al bot: el mismo cuerpo
            // sirve para cualquiera. Por eso su webhook lleva la cuenta en la
            // dirección, que es lo que Telegram sí permite configurar por bot.
            'telegram' => request()->route('externalId'),

            default => null,
        };
    }

    private function accountOf(string $tenantId, string $channel): ?ChannelAccountModel
    {
        /*
         * Se entra al negocio a mano: aquí no hubo subdominio que lo pusiera.
         *
         * Y con la sesión ENTERA, no sólo con el parámetro de PostgreSQL: el
         * ámbito global de Eloquent necesita además `TenantContext`, y sin él
         * esta consulta devuelve cero filas aunque RLS ya esté bien puesto.
         */
        $this->session->enter($tenantId);

        return ChannelAccountModel::where('channel', $channel)->first();
    }
}
