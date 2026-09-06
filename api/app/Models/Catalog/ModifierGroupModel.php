<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * A QUESTION put to whoever is ordering. `min_choices` and `max_choices` say
 * which kind: optional extras, pick-one, or exclusive.
 */
#[Fillable(['name', 'min_choices', 'max_choices', 'sort_order', 'is_active'])]
class ModifierGroupModel extends Model
{
    use BelongsToTenant, UsesUuidV7;

    protected $table = 'modifier_groups';

    protected function casts(): array
    {
        return [
            'min_choices' => 'integer',
            'max_choices' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<ModifierModel, $this> */
    public function modifiers(): HasMany
    {
        return $this->hasMany(ModifierModel::class, 'group_id')->orderBy('sort_order');
    }
}
