<?php

declare(strict_types=1);

namespace Modules\Channels;

use Platform\Modules\ModuleManifest;
use Platform\Modules\Setting;

/**
 * WhatsApp y Telegram: por donde llega la gente.
 *
 * En la práctica, para un negocio de comida en Venezuela, WhatsApp **es** el
 * canal. La gente no busca en Google ni abre una aplicación: escribe al mismo
 * número al que le escribe a su tía.
 *
 * El bot **no vende**: enseña la carta y manda al portal. Un carrito con
 * agregados dentro de un chat es una conversación de veinte mensajes que nadie
 * termina; el chat sirve para descubrir el menú, recibir avisos y hablar con
 * una persona cuando hace falta.
 *
 * Y **no lleva IA**, a propósito. Botones: el cliente toca y el sistema sabe
 * exactamente qué quiso decir. Un modelo que interpreta lenguaje libre acierta
 * casi siempre, y el «casi» son pedidos mal tomados que alguien paga.
 */
final class ChannelsModule extends ModuleManifest
{
    public function code(): string
    {
        return 'channels';
    }

    public function name(): string
    {
        return 'WhatsApp y Telegram';
    }

    public function description(): string
    {
        return 'Que el cliente vea la carta y reciba avisos por donde ya escribe.';
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        // Sin carta no hay nada que enseñar, y sin portal no hay a dónde
        // mandarlo a pedir.
        return ['catalog', 'orders', 'portal'];
    }

    public function routes(): ?string
    {
        return __DIR__.'/Interfaces/Http/Routes/api.php';
    }

    public function migrations(): ?string
    {
        return __DIR__.'/Infrastructure/Migrations';
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return [
            // Conectar el canal es pegar un token que permite escribir a todos
            // los clientes del negocio en su nombre. No es una preferencia.
            'channels.manage',

            // Leer los chats y contestar a mano cuando el bot no alcanza.
            'channels.view',
            'channels.reply',
        ];
    }

    /**
     * @return array<string, Setting>
     */
    public function settings(): array
    {
        return [
            // El saludo. Lo escribe el dueño una vez y es lo primero que lee
            // todo el que escribe al negocio.
            'greeting' => Setting::text('')->maxLength(300),

            /*
             * Avisar de cada cambio de estado.
             *
             * Encendido por defecto: un cliente que recibe «ya está listo» es
             * un cliente que no llama a preguntar, y esa llamada se la come
             * quien está cocinando.
             */
            'notify_status' => Setting::bool(true),

            /*
             * A los cuántos minutos sin hablar se suelta una conversación
             * tomada por una persona.
             *
             * Sin esto, el encargado atiende a alguien, se va a cerrar, y el
             * bot queda mudo para ese cliente para siempre.
             */
            'takeover_minutes' => Setting::int(60)->min(5)->max(1440),
        ];
    }
}
