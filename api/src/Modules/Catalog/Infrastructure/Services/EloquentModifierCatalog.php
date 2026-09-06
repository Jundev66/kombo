<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Services;

use App\Models\Catalog\ModifierModel;
use Modules\Catalog\Application\Contracts\ModifierCatalog;
use Modules\Catalog\Application\Contracts\ModifierSnapshot;
use Shared\Domain\ValueObjects\Money;

final class EloquentModifierCatalog implements ModifierCatalog
{
    /**
     * @param  list<string>  $modifierIds
     * @return array<string, ModifierSnapshot>
     */
    public function findMany(array $modifierIds): array
    {
        if ($modifierIds === []) {
            return [];
        }

        // One query for the whole order: ten lines with add-ons cannot cost thirty
        // round trips.
        return ModifierModel::whereIn('id', $modifierIds)
            ->get()
            ->mapWithKeys(fn (ModifierModel $m): array => [
                (string) $m->id => new ModifierSnapshot(
                    id: (string) $m->id,
                    name: (string) $m->name,
                    priceDelta: Money::fromCents((int) $m->price_delta_cents),
                    isActive: (bool) $m->is_active,
                ),
            ])
            ->all();
    }
}
