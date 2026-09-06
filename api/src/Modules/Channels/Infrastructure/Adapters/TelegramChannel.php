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
 * Telegram, through its Bot API. Different limits, hence a different adapter:
 *
 * - The keyboard is free — eight options fit in one message, and cutting them
 *   to three because WhatsApp cannot manage more would impoverish this channel.
 * - `callback_data` stops at 64 BYTES, and fails silently: the API accepts the
 *   message and the button does nothing. Checked here before sending.
 * - The signature is not HMAC but a header carrying a secret you chose.
 */
final class TelegramChannel implements MessagingChannel
{
    private const API = 'https://api.telegram.org';

    /** Two per row: what reads at a glance on a phone. */
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

        // All in ONE message, with the whole keyboard: splitting into batches of
        // three would import a limit this channel does not have.
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
        // A tapped button arrives as a `callback_query`, not as a message.
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
            // Edits, channel posts, people joining a group. Nothing to answer.
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
                // A button that will not work is not sent: Telegram would accept it and
                // tapping it would do nothing, the hardest failure this channel has to
                // diagnose.
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
            // As on WhatsApp: the notice is an extra, the food is the product.
            Log::warning('Telegram rechazó un mensaje', [
                'account' => $this->account->id,
                'method' => $method,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        }
    }
}
