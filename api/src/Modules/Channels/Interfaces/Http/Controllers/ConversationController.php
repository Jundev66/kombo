<?php

declare(strict_types=1);

namespace Modules\Channels\Interfaces\Http\Controllers;

use App\Models\Channels\ChannelAccountModel;
use App\Models\Channels\ConversationModel;
use App\Models\Channels\MessageModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Channels\Domain\ValueObjects\Reply;
use Modules\Channels\Infrastructure\Services\ChannelFactory;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The chats, for when the bot cannot cope.
 *
 * Replying by hand takes the conversation over and silences the bot; otherwise
 * the customer gets an automated menu on top of a person's answer.
 */
final class ConversationController
{
    public function __construct(private readonly ChannelFactory $channels) {}

    public function index(): JsonResponse
    {
        $conversations = ConversationModel::query()
            ->orderByDesc('last_message_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $conversations->map(fn (ConversationModel $c): array => [
                'id' => $c->id,
                'channel' => $c->channel,
                'customerName' => $c->customer_name,
                'customerPhone' => $c->customer_phone,
                'isHumanTakeover' => $c->is_human_takeover,
                'lastMessageAt' => $c->last_message_at?->toAtomString(),
            ])->all(),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $conversation = ConversationModel::with('messages')->find($id)
            ?? throw new NotFoundHttpException('Esa conversación no existe.');

        return response()->json([
            'data' => [
                'id' => $conversation->id,
                'channel' => $conversation->channel,
                'customerName' => $conversation->customer_name,
                'isHumanTakeover' => $conversation->is_human_takeover,
                'messages' => $conversation->messages->map(fn (MessageModel $m): array => [
                    'id' => $m->id,
                    'direction' => $m->direction,
                    'content' => $m->content,
                    'at' => $m->created_at?->toAtomString(),
                ])->all(),
            ],
        ]);
    }

    public function reply(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'min:1', 'max:1000'],
        ]);

        $conversation = ConversationModel::find($id)
            ?? throw new NotFoundHttpException('Esa conversación no existe.');

        $account = ChannelAccountModel::where('channel', $conversation->channel)->first();

        if ($account === null || ! $account->is_active) {
            throw new NotFoundHttpException('Ese canal ya no está conectado.');
        }

        $this->channels->for($account)->send($conversation->external_chat_id, Reply::text($data['text']));

        MessageModel::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'content' => $data['text'],
            'metadata' => ['by' => auth()->id()],
        ]);

        // Replying by hand TAKES the conversation over, silencing the bot for the
        // duration with nobody having to remember to.
        $conversation->update([
            'is_human_takeover' => true,
            'takeover_at' => now(),
            'state' => 'human',
            'last_message_at' => now(),
        ]);

        return response()->json(['data' => ['ok' => true]]);
    }

    /** Handing the conversation back to the bot. */
    public function release(string $id): JsonResponse
    {
        $conversation = ConversationModel::find($id)
            ?? throw new NotFoundHttpException('Esa conversación no existe.');

        $conversation->update([
            'is_human_takeover' => false,
            'takeover_at' => null,
            'state' => 'idle',
        ]);

        return response()->json(['data' => ['ok' => true]]);
    }
}
