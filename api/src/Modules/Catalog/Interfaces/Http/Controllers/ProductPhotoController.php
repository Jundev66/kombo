<?php

declare(strict_types=1);

namespace Modules\Catalog\Interfaces\Http\Controllers;

use App\Models\Catalog\ProductModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Catalog\Application\Exceptions\ProductNotFound;
use Platform\Tenancy\TenantContext;
use Shared\Domain\Exceptions\UserError;

/**
 * La foto de un producto.
 *
 * **Disco público, al revés que los comprobantes.** Y no es un descuido: la
 * foto de una arepa está para que la vea cualquiera que abra el portal — es lo
 * que vende. Un comprobante de pago lleva la cédula y el saldo de quien pagó, y
 * por eso aquél va a disco privado y se sirve por un controlador que comprueba
 * permisos. Distinta cosa, distinto sitio.
 *
 * Al reemplazar se **borra la anterior**. Sin eso, cada cambio de foto deja un
 * archivo huérfano para siempre, y el disco de un VPS pequeño no está para
 * guardar seis versiones de la misma arepa.
 */
final class ProductPhotoController
{
    private const DISK = 'public';

    public function __construct(private readonly TenantContext $context) {}

    public function store(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            /*
             * `image` valida el CONTENIDO, no la extensión: un `.jpg` que en
             * realidad es otra cosa no pasa.
             *
             * Y 4 MB de tope. La foto de un plato hecha con un teléfono ronda
             * los 2; el límite está para que nadie suba un vídeo por error y
             * llene el disco del local.
             */
            'photo' => ['required', 'image', 'max:4096'],
        ]);

        $product = ProductModel::find($id) ?? throw new ProductNotFound;

        $anterior = $product->photo_url;

        // Con el negocio delante en la ruta: dar de baja a un cliente es borrar
        // una carpeta, y un fallo que mezclara identificadores dejaría fotos de
        // dos negocios en el mismo sitio, donde se nota enseguida.
        $path = $data['photo']->store("products/{$this->context->id()}", self::DISK);

        if ($path === false) {
            throw new class('No se pudo guardar la foto. Inténtalo otra vez.') extends UserError
            {
                public function field(): ?string
                {
                    return 'photo';
                }
            };
        }

        /*
         * Se guarda una ruta RELATIVA, no una URL absoluta.
         *
         * `Storage::url()` la armaría con `APP_URL`, que es el dominio raíz —
         * y estas fotos se ven desde el subdominio de cada negocio. Con una
         * ruta relativa las sirve el mismo origen desde el que se abrió la
         * página, en desarrollo y en producción, sin que nadie tenga que
         * acordarse de configurar nada.
         */
        $product->update(['photo_url' => '/storage/'.$path]);

        $this->forget($anterior);

        return response()->json(['data' => ['photoUrl' => $product->refresh()->photo_url]]);
    }

    /** Quitarla: el producto se queda sin foto, y el archivo se va. */
    public function destroy(string $id): JsonResponse
    {
        $product = ProductModel::find($id) ?? throw new ProductNotFound;

        $anterior = $product->photo_url;

        $product->update(['photo_url' => null]);

        $this->forget($anterior);

        return response()->json(status: 204);
    }

    /**
     * Borra el archivo de una URL nuestra.
     *
     * Sólo si es nuestra: `photo_url` admite también una dirección de fuera
     * —así se cargaron las cartas antes de que existiera esto— y borrar por
     * ahí no tendría sentido.
     */
    private function forget(?string $url): void
    {
        if ($url === null) {
            return;
        }

        $prefijo = "/storage/products/{$this->context->id()}/";

        if (! str_starts_with($url, $prefijo)) {
            return;
        }

        Storage::disk(self::DISK)->delete("products/{$this->context->id()}/".basename($url));
    }
}
