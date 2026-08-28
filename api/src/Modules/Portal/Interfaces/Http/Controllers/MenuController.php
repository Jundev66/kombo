<?php

declare(strict_types=1);

namespace Modules\Portal\Interfaces\Http\Controllers;

use App\Models\Catalog\CategoryModel;
use App\Models\Catalog\ModifierGroupModel;
use App\Models\Catalog\ModifierModel;
use App\Models\Catalog\ProductModel;
use Illuminate\Http\JsonResponse;

/**
 * La carta, como la ve el cliente.
 *
 * **No es el listado del panel con otro nombre.** Aquí no hay paginación —el
 * cliente hace scroll, no pasa páginas—, no aparecen los productos apagados ni
 * los agotados, y no se filtra nada por permisos porque no hay usuario.
 *
 * Va todo en una llamada: categorías, productos y agregados. En un teléfono con
 * mala señal, tres peticiones para dibujar una carta son tres oportunidades de
 * que el cliente se vaya.
 */
final class MenuController
{
    public function __invoke(): JsonResponse
    {
        $products = ProductModel::query()
            ->with('modifierGroups:id')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            // Lo agotado no se ofrece. Enseñarlo en gris invita a preguntar
            // por teléfono si de verdad no queda.
            ->reject(fn (ProductModel $p): bool => $p->track_stock && ($p->stock_qty ?? 0) <= 0);

        $categories = CategoryModel::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            // Una categoría vacía es un enlace que no lleva a ninguna parte.
            ->filter(fn (CategoryModel $c): bool => $products->contains('category_id', $c->id));

        $groups = ModifierGroupModel::query()
            ->with(['modifiers' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'data' => [
                'categories' => $categories->map(fn (CategoryModel $c): array => [
                    'id' => $c->id,
                    'name' => $c->name,
                ])->values()->all(),

                'products' => $products->map(fn (ProductModel $p): array => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'description' => $p->description,
                    'photoUrl' => $p->photo_url,
                    'priceCents' => $p->price_cents,
                    'categoryId' => $p->category_id,
                    'prepMinutes' => $p->prep_minutes,
                    'modifierGroupIds' => $p->modifierGroups->pluck('id')->all(),
                ])->values()->all(),

                'modifierGroups' => $groups->map(fn (ModifierGroupModel $g): array => [
                    'id' => $g->id,
                    'name' => $g->name,
                    'minChoices' => $g->min_choices,
                    'maxChoices' => $g->max_choices,
                    'modifiers' => $g->modifiers->map(fn (ModifierModel $m): array => [
                        'id' => $m->id,
                        'name' => $m->name,
                        'priceDeltaCents' => $m->price_delta_cents,
                    ])->values()->all(),
                ])->values()->all(),
            ],
        ]);
    }
}
