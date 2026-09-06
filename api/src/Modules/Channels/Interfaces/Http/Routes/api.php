<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Channels\Interfaces\Http\Controllers\ChannelAccountController;
use Modules\Channels\Interfaces\Http\Controllers\ConversationController;
use Modules\Channels\Interfaces\Http\Controllers\WebhookController;

/*
 * The webhooks carry neither `auth:sanctum` nor `module:channels`, and both
 * absences are deliberate:
 *
 *   - There is no session: the caller is Meta or Telegram. What authenticates
 *     them is the SIGNATURE, verified before anything else.
 *   - `module:` answers 404 by looking at the tenant in context, and there is
 *     none yet: the tenant is resolved INSIDE, from the body. A switched-off
 *     module still shows — with no account configured the webhook does nothing.
 *
 * They sit outside `/api/v1` because they are an address pasted into Meta's
 * console; versioning it would make every customer reconfigure.
 */
Route::middleware('api')->group(function (): void {
    Route::get('/webhooks/{channel}', [WebhookController::class, 'verify'])
        ->where('channel', 'whatsapp|telegram');

    Route::post('/webhooks/{channel}', WebhookController::class)
        ->where('channel', 'whatsapp|telegram');

    // Telegram sends nothing that identifies the bot, so its account goes in
    // the address — the one thing Telegram lets you configure per bot.
    Route::post('/webhooks/telegram/{externalId}', WebhookController::class)
        ->defaults('channel', 'telegram');
});

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', 'module:channels'])
    ->group(function (): void {
        Route::get('/channels', [ChannelAccountController::class, 'index'])
            ->middleware('permission:channels.view');

        // Connecting a channel means pasting a token that can write to every
        // customer in the tenant's name. Not a preference.
        Route::put('/channels/{channel}', [ChannelAccountController::class, 'save'])
            ->middleware('permission:channels.manage');

        Route::delete('/channels/{channel}', [ChannelAccountController::class, 'disconnect'])
            ->middleware('permission:channels.manage');

        Route::get('/conversations', [ConversationController::class, 'index'])
            ->middleware('permission:channels.view');

        Route::get('/conversations/{id}', [ConversationController::class, 'show'])
            ->middleware('permission:channels.view');

        Route::post('/conversations/{id}/reply', [ConversationController::class, 'reply'])
            ->middleware('permission:channels.reply');

        // Handing the conversation back to the bot once the manager has finished.
        Route::post('/conversations/{id}/release', [ConversationController::class, 'release'])
            ->middleware('permission:channels.reply');
    });
