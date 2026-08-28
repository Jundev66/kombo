<?php

declare(strict_types=1);

namespace Modules\Channels\Infrastructure\Adapters;

use App\Models\Channels\ChannelAccountModel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Channels\Domain\Ports\MessagingChannel;
use Modules\Channels\Domain\ValueObjects\IncomingMessage;
use Modules\Channels\Domain\ValueObjects\Reply;

/**
 * WhatsApp, por la API de Meta.
 *
 * Los límites de este canal viven aquí y en ningún otro sitio:
 *
 *   - **Tres botones por mensaje.** Seis opciones son dos mensajes.
 *   - **Veinte caracteres por botón.** Los nombres largos se recortan.
 *   - **Ventana de 24 horas.** Fuera de ella sólo se pueden mandar plantillas
 *     aprobadas por Meta. Aquí no se mandan: un aviso que falla en silencio es
 *     peor que no prometerlo, así que se registra y se sigue.
 *
 * Y una advertencia sobre los números de prueba de Meta, que cuesta un día
 * descubrir: **rechazan los mensajes de tipo lista** (error 131009). Sólo los
 * botones funcionan de forma fiable. Por eso este adaptador no usa listas
 * aunque tres botones se queden cortos.
 */
final class WhatsAppChannel implements MessagingChannel
{
    private const API = 'https://graph.facebook.com/v21.0';

    private const MAX_BUTTONS = 3;

    private const MAX_BUTTON_CHARS = 20;

    public function __construct(private readonly ChannelAccountModel $account) {}

    public function code(): string
    {
        return 'whatsapp';
    }

    public function send(string $chatId, Reply $reply): void
    {
        if ($reply->imageUrl !== null) {
            $this->post([
                'messaging_product' => 'whatsapp',
                'to' => $chatId,
                'type' => 'image',
                'image' => ['link' => $reply->imageUrl, 'caption' => $reply->text],
            ]);

            return;
        }

        if (! $reply->hasOptions()) {
            $this->post([
                'messaging_product' => 'whatsapp',
                'to' => $chatId,
                'type' => 'text',
                'text' => ['body' => $reply->text],
            ]);

            return;
        }

        /*
         * Más de tres opciones se parten en varios mensajes.
         *
         * El primero lleva el texto; los siguientes, un «y también». Repetir la
         * pregunta entera en cada tanda haría que el cliente la lea tres veces
         * y no sepa cuál contestar.
         */
        foreach (array_chunk($reply->options, self::MAX_BUTTONS) as $index => $tanda) {
            $this->post([
                'messaging_product' => 'whatsapp',
                'to' => $chatId,
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'button',
                    'body' => ['text' => $index === 0 ? $reply->text : 'Y también:'],
                    'action' => [
                        'buttons' => array_map(fn ($option): array => [
                            'type' => 'reply',
                            'reply' => [
                                'id' => $option->id,
                                'title' => $option->labelWithin(self::MAX_BUTTON_CHARS),
                            ],
                        ], $tanda),
                    ],
                ],
            ]);
        }
    }

    /**
     * La firma de Meta: HMAC-SHA256 del cuerpo CRUDO.
     *
     * Del cuerpo tal como llegó, no del que se obtiene al volver a serializar
     * lo que se decodificó: un espacio de diferencia y la firma no cuadra.
     */
    public function verifySignature(string $payload, array $headers, ?string $secret): bool
    {
        if ($secret === null || $secret === '') {
            // Sin secreto configurado no se puede comprobar nada, y aceptar
            // «porque todavía no está configurado» es dejar la puerta abierta
            // justo el tiempo que tarde alguien en encontrarla.
            return false;
        }

        $signature = $headers['x-hub-signature-256'][0] ?? $headers['X-Hub-Signature-256'][0] ?? '';

        $expected = 'sha256='.hash_hmac('sha256', $payload, $secret);

        // `hash_equals` y no `===`: comparar cadenas byte a byte filtra por
        // tiempos cuántos aciertan, que es suficiente para adivinar una firma.
        return is_string($signature) && hash_equals($expected, $signature);
    }

    /**
     * @return list<IncomingMessage>
     */
    public function parse(array $payload): array
    {
        $messages = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                // Los nombres vienen aparte de los mensajes, en `contacts`.
                $names = [];
                foreach ($value['contacts'] ?? [] as $contact) {
                    $names[$contact['wa_id'] ?? ''] = $contact['profile']['name'] ?? null;
                }

                foreach ($value['messages'] ?? [] as $message) {
                    $from = (string) ($message['from'] ?? '');

                    if ($from === '') {
                        continue;
                    }

                    $messages[] = new IncomingMessage(
                        externalId: (string) ($message['id'] ?? ''),
                        chatId: $from,
                        text: (string) ($message['text']['body'] ?? ''),
                        optionId: $message['interactive']['button_reply']['id'] ?? null,
                        senderName: $names[$from] ?? null,
                        senderPhone: $from,
                    );
                }

                /*
                 * `statuses` trae avisos de entregado y leído. Se descartan
                 * aquí: no son mensajes de nadie, y dejarlos pasar haría que el
                 * bot conteste un menú cada vez que el cliente abre el chat.
                 */
            }
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function post(array $body): void
    {
        $token = $this->account->credential('access_token');
        $phoneNumberId = $this->account->external_id;

        if ($token === null) {
            Log::warning('Canal de WhatsApp sin credenciales', ['account' => $this->account->id]);

            return;
        }

        $response = Http::withToken($token)
            ->timeout(10)
            ->post(self::API."/{$phoneNumberId}/messages", $body);

        if ($response->failed()) {
            /*
             * No se lanza excepción.
             *
             * Esto corre en la cola, disparado por un cambio de estado del
             * pedido. Que WhatsApp esté caído no puede hacer que el pedido se
             * quede sin avanzar: el aviso es un extra, la comida es el
             * producto.
             */
            Log::warning('WhatsApp rechazó un mensaje', [
                'account' => $this->account->id,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        }
    }
}
