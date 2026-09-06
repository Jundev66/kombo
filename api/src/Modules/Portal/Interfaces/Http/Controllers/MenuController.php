<?php

declare(strict_types=1);

namespace Modules\Portal\Interfaces\Http\Controllers;

use App\Models\Catalog\CategoryModel;
use App\Models\Catalog\ModifierGroupModel;
use App\Models\Catalog\ModifierModel;
use App\Models\Catalog\ProductModel;
use Illuminate\Http\JsonResponse;

/**
 * The menu, as the customer sees it. Not the dashboard listing renamed: no
 * pagination, no switched-off or sold-out products, no permission filtering.
 *
 * Categories, products and add-ons in one call — on a phone with poor signal,
 * three requests are three chances for the customer to leave.
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
            // What has run out is not offered: showing it greyed out invites a phone
            // call asking whether there really is none left.
            ->reject(fn (ProductModel $p): bool => $p->track_stock && ($p->stock_qty ?? 0) <= 0);

        $categories = CategoryModel::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            // An empty category is a link that leads nowhere.
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
