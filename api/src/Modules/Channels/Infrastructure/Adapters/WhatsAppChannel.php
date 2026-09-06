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
 * WhatsApp, through Meta's API. This channel's limits live here and nowhere
 * else: three buttons per message, twenty characters per button, and a 24-hour
 * window outside which only approved templates may be sent (none are — a notice
 * that fails silently is worse than not promising it).
 *
 * And a warning that costs a day to discover: Meta's test numbers reject
 * list-type messages (error 131009), so this adapter uses buttons only.
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
         * More than three options are split across messages. The first carries
         * the text, the rest say "and also": repeating the whole question would
         * have the customer read it three times without knowing which to answer.
         */
        foreach (array_chunk($reply->options, self::MAX_BUTTONS) as $index => $batch) {
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
                        ], $batch),
                    ],
                ],
            ]);
        }
    }

    /**
     * Meta's signature: HMAC-SHA256 of the RAW body — exactly as it arrived,
     * not re-serialised from what was decoded. One space of difference and the
     * signature does not match.
     */
    public function verifySignature(string $payload, array $headers, ?string $secret): bool
    {
        if ($secret === null || $secret === '') {
            // With no configured secret nothing can be verified, and accepting
            // "because it is not configured yet" leaves the door open exactly as long
            // as it takes somebody to find it.
            return false;
        }

        $signature = $headers['x-hub-signature-256'][0] ?? $headers['X-Hub-Signature-256'][0] ?? '';

        $expected = 'sha256='.hash_hmac('sha256', $payload, $secret);

        // `hash_equals` rather than `===`: byte-by-byte comparison leaks through
        // timing how many characters match.
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

                // The names arrive separately from the messages, in `contacts`.
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
                 * `statuses` carries delivered and read receipts, discarded
                 * here: they are nobody's message, and letting them through
                 * would answer with a menu every time the customer opens the
                 * chat.
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
             * No exception is thrown. This runs on the queue, fired by an order
             * changing state: WhatsApp being down cannot stop the order
             * advancing. The notice is an extra, the food is the product.
             */
            Log::warning('WhatsApp rechazó un mensaje', [
                'account' => $this->account->id,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        }
    }
}
