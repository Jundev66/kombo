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
 * Contestar a un cliente, fuera de la petición del webhook.
 *
 * Meta corta a los 30 segundos y reintenta lo que no conteste a tiempo.
 * Consultar la carta, escribir la conversación y llamar a la API del canal
 * puede llevar más que eso en una máquina ocupada — y entonces empieza la
 * tormenta: el reintento entra, se procesa otra vez, tarda otra vez, y así.
 *
 * Por eso el webhook responde 200 en el acto y todo lo demás pasa aquí.
 */
final class ProcessIncomingMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Tres intentos, no más.
     *
     * Si el canal está caído, insistir veinte veces no lo levanta y sí llena la
     * cola de un negocio que además está cocinando.
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

                // Se guarda lo que se dijo, no sólo lo que nos dijeron: sin
                // eso, la pantalla de conversaciones enseña media charla.
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

        // El nombre puede llegar más tarde que la primera línea, y cambiar: la
        // gente se pone y se quita el nombre del perfil.
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
