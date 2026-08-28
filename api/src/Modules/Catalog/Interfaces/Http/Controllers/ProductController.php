<?php

declare(strict_types=1);

namespace Modules\Catalog\Interfaces\Http\Controllers;

use App\Models\Catalog\ProductModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Application\Exceptions\ProductNotFound;
use Modules\Catalog\Application\UseCases\CreateProduct;
use Modules\Catalog\Application\UseCases\UpdateProduct;
use Modules\Catalog\Interfaces\Http\Requests\StoreProductRequest;
use Modules\Catalog\Interfaces\Http\Resources\ProductResource;
use Platform\Capabilities\CurrentCapabilities;

/**
 * La carta, por HTTP.
 *
 * El controlador no lleva reglas de negocio: valida la forma, llama al caso de
 * uso y devuelve. Si aquí apareciera un `if` sobre precios o existencias, esa
 * regla dejaría de valer para el bot y para la caja.
 */
final class ProductController
{
    public function index(Request $request, CurrentCapabilities $capabilities): JsonResponse
    {
        $perPage = (int) $capabilities->get()->setting('catalog.page_size', 50);

        $products = ProductModel::query()
            ->with('modifierGroups:id')
            ->when(
                $request->string('category')->isNotEmpty(),
                fn ($q) => $q->where('category_id', $request->string('category')->toString()),
            )
            ->when(
                $request->string('buscar')->isNotEmpty(),
                // `ilike` y no `like`: nadie escribe «Reina Pepiada» con las
                // mayúsculas exactas cuando está buscando con prisa.
                fn ($q) => $q->where('name', 'ilike', '%'.$request->string('buscar')->toString().'%'),
            )
            // Sólo los de la carta, salvo que se pidan todos: el panel necesita
            // ver los desactivados para reactivarlos, la caja no.
            ->when(! $request->boolean('incluir_inactivos'), fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => array_map(ProductResource::make(...), $products->items()),
            'meta' => [
                'page' => $products->currentPage(),
                'lastPage' => $products->lastPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $product = ProductModel::with('modifierGroups:id')->find($id) ?? throw new ProductNotFound;

        return response()->json(['data' => ProductResource::make($product)]);
    }

    public function store(StoreProductRequest $request, CreateProduct $createProduct): JsonResponse
    {
        $data = $request->validated();

        $product = $createProduct->execute(
            name: $data['name'],
            priceCents: $data['price_cents'],
            categoryId: $data['category_id'] ?? null,
            description: $data['description'] ?? null,
            photoUrl: $data['photo_url'] ?? null,
            prepMinutes: $data['prep_minutes'] ?? null,
            trackStock: (bool) ($data['track_stock'] ?? false),
            stockQty: $data['stock_qty'] ?? null,
            modifierGroupIds: $data['modifier_group_ids'] ?? [],
        );

        return response()->json(
            ['data' => ProductResource::make($product->load('modifierGroups:id'))],
            201,
        );
    }

    public function update(Request $request, string $id, UpdateProduct $updateProduct): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'category_id' => ['sometimes', 'nullable', 'uuid'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'photo_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'prep_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:240'],
            'track_stock' => ['sometimes', 'boolean'],
            'stock_qty' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'modifier_group_ids' => ['sometimes', 'array'],
            'modifier_group_ids.*' => ['uuid'],
        ]);

        // `price_cents` NO está arriba, y es deliberado: el precio se cambia
        // por su propia ruta, con su propio permiso y dejando rastro. Si se
        // colara aquí, ese permiso aparte sería decorativo.
        $product = $updateProduct->execute(
            productId: $id,
            name: $data['name'] ?? null,
            categoryId: $data['category_id'] ?? null,
            description: $data['description'] ?? null,
            photoUrl: $data['photo_url'] ?? null,
            prepMinutes: $data['prep_minutes'] ?? null,
            trackStock: $data['track_stock'] ?? null,
            stockQty: $data['stock_qty'] ?? null,
            isActive: $data['is_active'] ?? null,
            modifierGroupIds: $data['modifier_group_ids'] ?? null,
        );

        return response()->json(['data' => ProductResource::make($product->load('modifierGroups:id'))]);
    }
}
