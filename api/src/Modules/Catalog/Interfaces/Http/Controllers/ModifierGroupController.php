<?php

declare(strict_types=1);

namespace Modules\Catalog\Interfaces\Http\Controllers;

use App\Models\Catalog\ModifierGroupModel;
use App\Models\Catalog\ModifierModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Domain\ValueObjects\SelectionRule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The add-ons: "no onion", "extra cheese", "how would you like the meat".
 *
 * A group is a question and its modifiers are the answers. Saved together in
 * one call, because a question with no answers is useless on the menu.
 */
final class ModifierGroupController
{
    public function index(): JsonResponse
    {
        $groups = ModifierGroupModel::with('modifiers')->orderBy('sort_order')->get();

        return response()->json([
            'data' => $groups->map(fn (ModifierGroupModel $g): array => [
                'id' => $g->id,
                'name' => $g->name,
                'minChoices' => $g->min_choices,
                'maxChoices' => $g->max_choices,
                // The text whoever is ordering will see, resolved on the server so the
                // portal, the till and the bot all say the same thing.
                'rule' => SelectionRule::of($g->min_choices, $g->max_choices)->explain(),
                'isActive' => $g->is_active,
                'modifiers' => $g->modifiers->map(fn (ModifierModel $m): array => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'priceDeltaCents' => $m->price_delta_cents,
                    'isActive' => $m->is_active,
                ]),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        // The rule is built BEFORE anything is written, so an impossible one leaves
        // no half-built group on the menu.
        SelectionRule::of($data['min_choices'] ?? 0, $data['max_choices'] ?? 1);

        $group = DB::transaction(function () use ($data): ModifierGroupModel {
            $group = ModifierGroupModel::create([
                'name' => $data['name'],
                'min_choices' => $data['min_choices'] ?? 0,
                'max_choices' => $data['max_choices'] ?? 1,
                'sort_order' => $data['sort_order'] ?? 0,
            ]);

            foreach (array_values($data['modifiers'] ?? []) as $i => $modifier) {
                $group->modifiers()->create([
                    'name' => $modifier['name'],
                    // Can be NEGATIVE: "no cheese" sometimes takes money off.
                    'price_delta_cents' => $modifier['price_delta_cents'] ?? 0,
                    'sort_order' => $i,
                ]);
            }

            return $group;
        });

        return response()->json(['data' => ['id' => $group->id, 'name' => $group->name]], 201);
    }

    public function destroy(string $id): JsonResponse
    {
        $group = ModifierGroupModel::find($id) ?? throw new NotFoundHttpException('Ese grupo no existe.');

        $group->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'min_choices' => ['integer', 'min:0'],
            'max_choices' => ['integer', 'min:1'],
            'sort_order' => ['integer', 'min:0'],
            'modifiers' => ['array'],
            'modifiers.*.name' => ['required', 'string', 'max:80'],
            // No `min:0`: a modifier MAY take money off.
            'modifiers.*.price_delta_cents' => ['integer'],
        ]);
    }
}
