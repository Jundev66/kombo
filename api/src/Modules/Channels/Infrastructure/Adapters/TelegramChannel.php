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
 * Telegram, por su Bot API.
 *
 * Aquí los límites son otros, y por eso el adaptador es otro:
 *
 *   - **El teclado es libre.** Ocho opciones caben en un mensaje, en dos
 *     columnas. Recortarlas a tres porque WhatsApp no da para más sería
 *     empobrecer este canal sin ninguna razón.
 *   - **`callback_data` no pasa de 64 BYTES.** Y falla de la peor manera: la
 *     API acepta el mensaje y el botón sencillamente no hace nada. Por eso los
 *     identificadores del motor son cortos por construcción, y aquí se
 *     comprueba antes de mandar.
 *   - **La firma no es HMAC**: es una cabecera con un secreto que uno mismo
 *     eligió al dar de alta el webhook.
 */
final class TelegramChannel implements MessagingChannel
{
    private const API = 'https://api.telegram.org';

    /** Dos por fila: lo que se lee de un vistazo en un teléfono. */
    private const COLUMNS = 2;

    private const MAX_CALLBACK_BYTES = 64;

    public function __construct(private readonly ChannelAccountModel $account) {}

    public function code(): string
    {
        return 'telegram';
    }

    public function send(string $chatId, Reply $reply): void
    {
        if ($reply->imageUrl !== null) {
            $this->call('sendPhoto', [
                'chat_id' => $chatId,
                'photo' => $reply->imageUrl,
                'caption' => $reply->text,
                ...$this->keyboard($reply),
            ]);

            return;
        }

        // Todo en UN mensaje, con el teclado entero. Partirlo en tandas de tres
        // sería traer aquí un límite que este canal no tiene.
        $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $reply->text,
            ...$this->keyboard($reply),
        ]);
    }

    public function verifySignature(string $payload, array $headers, ?string $secret): bool
    {
        if ($secret === null || $secret === '') {
            return false;
        }

        $sent = $headers['x-telegram-bot-api-secret-token'][0]
            ?? $headers['X-Telegram-Bot-Api-Secret-Token'][0]
            ?? '';

        return is_string($sent) && hash_equals($secret, $sent);
    }

    /**
     * @return list<IncomingMessage>
     */
    public function parse(array $payload): array
    {
        // Un botón tocado llega como `callback_query`, no como mensaje.
        if (isset($payload['callback_query'])) {
            $callback = $payload['callback_query'];
            $chat = $callback['message']['chat'] ?? [];

            return [new IncomingMessage(
                externalId: 'cb:'.($callback['id'] ?? ''),
                chatId: (string) ($chat['id'] ?? ''),
                optionId: (string) ($callback['data'] ?? ''),
                senderName: self::nameOf($callback['from'] ?? []),
            )];
        }

        $message = $payload['message'] ?? null;

        if ($message === null) {
            // Ediciones, mensajes de canal, gente que entra a un grupo. Nada
            // que contestar.
            return [];
        }

        $chatId = (string) ($message['chat']['id'] ?? '');

        if ($chatId === '') {
            return [];
        }

        return [new IncomingMessage(
            externalId: (string) ($payload['update_id'] ?? $message['message_id'] ?? ''),
            chatId: $chatId,
            text: (string) ($message['text'] ?? ''),
            senderName: self::nameOf($message['from'] ?? []),
            senderPhone: $message['contact']['phone_number'] ?? null,
        )];
    }

    /**
     * @param  array<string, mixed>  $from
     */
    private static function nameOf(array $from): ?string
    {
        $name = trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? ''));

        return $name !== '' ? $name : ($from['username'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function keyboard(Reply $reply): array
    {
        if (! $reply->hasOptions()) {
            return [];
        }

        $buttons = [];

        foreach ($reply->options as $option) {
            if (! $option->fitsEverywhere()) {
                // No se manda un botón que no va a funcionar: Telegram lo
                // aceptaría y al tocarlo no pasaría nada, que es el fallo más
                // difícil de diagnosticar que tiene este canal.
                Log::warning('Opción descartada: callback_data pasa de 64 bytes', [
                    'id' => $option->id,
                    'bytes' => strlen($option->id),
                    'limite' => self::MAX_CALLBACK_BYTES,
                ]);

                continue;
            }

            $buttons[] = ['text' => $option->label, 'callback_data' => $option->id];
        }

        return [
            'reply_markup' => json_encode([
                'inline_keyboard' => array_chunk($buttons, self::COLUMNS),
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function call(string $method, array $params): void
    {
        $token = $this->account->credential('bot_token');

        if ($token === null) {
            Log::warning('Canal de Telegram sin credenciales', ['account' => $this->account->id]);

            return;
        }

        $response = Http::timeout(10)->asForm()->post(self::API."/bot{$token}/{$method}", $params);

        if ($response->failed()) {
            // Como en WhatsApp: el aviso es un extra, la comida es el producto.
            Log::warning('Telegram rechazó un mensaje', [
                'account' => $this->account->id,
                'method' => $method,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        }
    }
}
