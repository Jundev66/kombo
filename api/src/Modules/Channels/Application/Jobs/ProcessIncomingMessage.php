<?php

declare(strict_types=1);

namespace Modules\Channels\Application\Jobs;

use App\Models\Channels\ChannelAccountModel;
use App\Models\Channels\ConversationModel;
use App\Models\Channels\MessageModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Channels\Application\UseCases\ConversationEngine;
use Modules\Channels\Domain\ValueObjects\IncomingMessage;
use Modules\Channels\Infrastructure\Services\ChannelFactory;
use Platform\Tenancy\TenantSession;

/**
 * Answering a customer, outside the webhook request.
 *
 * Meta cuts off at 30 seconds and retries what does not answer in time. On a
 * busy machine the work takes longer than that, and then the storm starts — so
 * the webhook answers 200 immediately and everything else happens here.
 */
final class ProcessIncomingMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Three attempts, no more: if the channel is down, insisting twenty times
     * does not bring it back and does fill the queue of a tenant that is also
     * cooking.
     */
    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        private readonly string $tenantId,
        private readonly string $channel,
        private readonly IncomingMessage $message,
    ) {}

    public function handle(
        TenantSession $session,
        ChannelFactory $channels,
        ConversationEngine $engine,
    ): void {
        $session->within($this->tenantId, function () use ($channels, $engine): void {
            $account = ChannelAccountModel::where('channel', $this->channel)->first();

            if ($account === null || ! $account->is_active) {
                return;
            }

            $conversation = $this->conversation();

            $this->record($conversation, 'in', $this->message->text, $this->message->externalId);

            $replies = $engine->respond($conversation, $this->message);

            $adapter = $channels->for($account);

            foreach ($replies as $reply) {
                $adapter->send($this->message->chatId, $reply);

                // What was said is stored too, not only what was said to us: otherwise the
                // conversations screen shows half a chat.
                $this->record($conversation, 'out', $reply->text, null);
            }

            $conversation->update(['last_message_at' => now()]);
            $account->update(['last_message_at' => now()]);
        });
    }

    private function conversation(): ConversationModel
    {
        $conversation = ConversationModel::firstOrCreate(
            [
                'channel' => $this->channel,
                'external_chat_id' => $this->message->chatId,
            ],
            [
                'customer_name' => $this->message->senderName,
                'customer_phone' => $this->message->senderPhone,
            ],
        );

        // The name can arrive later than the first line, and change: people put
        // their profile name up and take it down.
        if ($this->message->senderName !== null && $conversation->customer_name !== $this->message->senderName) {
            $conversation->update(['customer_name' => $this->message->senderName]);
        }

        return $conversation;
    }

    private function record(
        ConversationModel $conversation,
        string $direction,
        ?string $content,
        ?string $externalId,
    ): void {
        MessageModel::create([
            'conversation_id' => $conversation->id,
            'direction' => $direction,
            'content' => $content,
            'external_id' => $externalId,
        ]);
    }
}
