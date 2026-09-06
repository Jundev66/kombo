<?php

declare(strict_types=1);

namespace Modules\Catalog\Interfaces\Http\Controllers;

use App\Models\Catalog\CategoryModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The sections of the menu: arepas, drinks, desserts. A sixty-product menu with
 * no categories is a list nobody can scan with a customer standing there.
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

        // Products are NOT deleted with it: the foreign key leaves them without a
        // category (`set null`).
        $category->delete();

        return response()->json(status: 204);
    }
}
