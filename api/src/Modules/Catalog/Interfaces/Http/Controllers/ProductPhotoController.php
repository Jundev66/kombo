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
 * A product's photo. Public disk, unlike payment receipts.
 *
 * Not an oversight: the photo is meant to be seen by anyone who opens the
 * portal. A receipt carries the payer's ID number and balance, so that one is
 * private and served through a controller that checks permissions.
 *
 * On replacement the previous file is deleted, or every change would leave an
 * orphan on a small VPS's disk.
 */
final class ProductPhotoController
{
    private const DISK = 'public';

    public function __construct(private readonly TenantContext $context) {}

    public function store(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            /*
             * `image` validates the CONTENT, not the extension. And a 4 MB cap:
             * a phone photo of a dish is around 2, and the limit stops a video
             * uploaded by mistake filling the shop's disk.
             */
            'photo' => ['required', 'image', 'max:4096'],
        ]);

        $product = ProductModel::find($id) ?? throw new ProductNotFound;

        $previous = $product->photo_url;

        // The tenant goes up front in the path: removing a customer is deleting one
        // directory, and a bug that mixed ids would show up immediately.
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
         * A RELATIVE path is stored, not an absolute URL: `Storage::url()`
         * would build it from `APP_URL`, the root domain, while these photos
         * are viewed from each tenant's subdomain.
         */
        $product->update(['photo_url' => '/storage/'.$path]);

        $this->forget($previous);

        return response()->json(['data' => ['photoUrl' => $product->refresh()->photo_url]]);
    }

    /** Removing it: the product is left photoless, and the file goes. */
    public function destroy(string $id): JsonResponse
    {
        $product = ProductModel::find($id) ?? throw new ProductNotFound;

        $previous = $product->photo_url;

        $product->update(['photo_url' => null]);

        $this->forget($previous);

        return response()->json(status: 204);
    }

    /**
     * Deletes the file behind one of our URLs, and only ours — `photo_url` also
     * accepts an outside address.
     */
    private function forget(?string $url): void
    {
        if ($url === null) {
            return;
        }

        $prefix = "/storage/products/{$this->context->id()}/";

        if (! str_starts_with($url, $prefix)) {
            return;
        }

        Storage::disk(self::DISK)->delete("products/{$this->context->id()}/".basename($url));
    }
}
