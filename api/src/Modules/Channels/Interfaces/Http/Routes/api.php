<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Channels\Interfaces\Http\Controllers\ChannelAccountController;
use Modules\Channels\Interfaces\Http\Controllers\ConversationController;
use Modules\Channels\Interfaces\Http\Controllers\WebhookController;

/*
 * Los webhooks NO llevan `auth:sanctum` ni `module:channels`, y las dos
 * ausencias son deliberadas:
 *
 *   - No hay sesión: quien llama es Meta o Telegram, no una persona. Lo que
 *     los autentica es la FIRMA, que el controlador comprueba antes que nada.
 *
 *   - No llevan `module:` porque `module:` responde 404 mirando el negocio en
 *     contexto, y aquí todavía no hay contexto: el negocio se resuelve DENTRO,
 *     a partir del identificador que trae el cuerpo. Que el módulo esté apagado
 *     se nota igual: sin cuenta configurada, el webhook contesta 200 y no hace
 *     nada.
 *
 * Van fuera de `/api/v1` porque son una dirección que se pega en la consola de
 * Meta y no cambia nunca; versionarla obligaría a que todos los clientes
 * volvieran a configurarla el día que aparezca una v2.
 */
Route::middleware('api')->group(function (): void {
    Route::get('/webhooks/{channel}', [WebhookController::class, 'verify'])
        ->where('channel', 'whatsapp|telegram');

    Route::post('/webhooks/{channel}', WebhookController::class)
        ->where('channel', 'whatsapp|telegram');

    // Telegram no manda nada que identifique al bot, así que su cuenta va en la
    // dirección — que es lo único que Telegram sí deja configurar por bot.
    Route::post('/webhooks/telegram/{externalId}', WebhookController::class)
        ->defaults('channel', 'telegram');
});

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', 'module:channels'])
    ->group(function (): void {
        Route::get('/channels', [ChannelAccountController::class, 'index'])
            ->middleware('permission:channels.view');

        // Conectar un canal es pegar un token que permite escribir a todos los
        // clientes del negocio en su nombre. No es una preferencia.
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

        // Devolver la conversación al bot cuando el encargado ya terminó.
        Route::post('/conversations/{id}/release', [ConversationController::class, 'release'])
            ->middleware('permission:channels.reply');
    });
