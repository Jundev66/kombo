<?php

declare(strict_types=1);

namespace App\Models\Kitchen;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Kitchen\Domain\ValueObjects\TicketStatus;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * A kitchen ticket. What the kitchen has in front of it.
 */
#[Fillable([
    'order_id', 'number', 'status', 'service_type', 'station',
    'taken_by_name', 'notes', 'prep_minutes',
    'placed_at', 'started_at', 'ready_at', 'served_at', 'cancelled_at',
])]
class KitchenTicketModel extends Model
{
    use BelongsToTenant, UsesUuidV7;

    protected $table = 'kitchen_tickets';

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'number' => 'integer',
            'prep_minutes' => 'integer',
            'placed_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'ready_at' => 'immutable_datetime',
            'served_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<KitchenTicketItemModel, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(KitchenTicketItemModel::class, 'ticket_id')->orderBy('sort_order');
    }
}
