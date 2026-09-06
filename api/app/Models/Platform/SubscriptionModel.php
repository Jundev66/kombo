<?php

declare(strict_types=1);

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * A tenant's subscription.
 *
 * Everything turns on `current_period_end`. No flags anyone has to remember to
 * move: a date, and a daily job that looks at it.
 */
#[Fillable([
    'tenant_id', 'plan_code', 'status', 'started_at',
    'current_period_end', 'grace_days', 'cancelled_at', 'notes',
])]
class SubscriptionModel extends Model
{
    use UsesUuidV7;

    protected $table = 'subscriptions';

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'current_period_end' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'grace_days' => 'integer',
        ];
    }

    /** @return HasMany<SubscriptionPaymentModel, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPaymentModel::class, 'subscription_id')->latest('paid_at');
    }

    /** When it stops being able to write if nobody pays. */
    public function suspendsAt(): \DateTimeImmutable
    {
        return $this->current_period_end->addDays($this->grace_days)->toDateTimeImmutable();
    }

    public function daysLeft(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->current_period_end->startOfDay(), false);
    }
}
