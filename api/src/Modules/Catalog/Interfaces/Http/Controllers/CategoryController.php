<?php

declare(strict_types=1);

namespace Modules\Catalog\Interfaces\Http\Controllers;

use App\Models\Catalog\CategoryModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Las secciones de la carta.
 *
 * Existen para que la caja tenga menos que mirar: arepas, bebidas, postres.
 * Una carta de sesenta productos sin categorías es una lista imposible de
 * recorrer con un cliente delante.
 */
final class CategoryController
{
    public function index(): JsonResponse
    {
        $categories = CategoryModel::query()
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $categories->map(fn (CategoryModel $c): array => [
                'id' => $c->id,
                'name' => $c->name,
                'sortOrder' => $c->sort_order,
                'isActive' => $c->is_active,
                'productCount' => $c->products_count,
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $category = CategoryModel::create($data);

        return response()->json(['data' => ['id' => $category->id, 'name' => $category->name]], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $category = CategoryModel::find($id) ?? throw new NotFoundHttpException('Esa categoría no existe.');

        $category->update($request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]));

        return response()->json(['data' => ['id' => $category->id, 'name' => $category->name]]);
    }

    public function destroy(string $id): JsonResponse
    {
        $category = CategoryModel::find($id) ?? throw new NotFoundHttpException('Esa categoría no existe.');

        // Los productos NO se borran con ella: la clave foránea los deja sin
        // categoría (`set null`). Borrar una sección de la carta no puede
        // llevarse por delante lo que se vende en ella.
        $category->delete();

        return response()->json(status: 204);
    }
}
