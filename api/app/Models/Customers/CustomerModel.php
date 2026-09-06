<?php

declare(strict_types=1);

namespace App\Models\Customers;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * A customer of the tenant.
 *
 * The phone number is stored encrypted with its hash alongside: Laravel's
 * encryption is not deterministic, so without the hash there would be no way to
 * find someone by number without decrypting the whole table.
 */
#[Fillable(['phone', 'phone_hash', 'name', 'notes', 'orders_count', 'spent_cents', 'last_order_at'])]
class CustomerModel extends Model
{
    use BelongsToTenant, UsesUuidV7;

    protected $table = 'customers';

    protected function casts(): array
    {
        return [
            'phone' => 'encrypted',
            'orders_count' => 'integer',
            'spent_cents' => 'integer',
            'last_order_at' => 'immutable_datetime',
        ];
    }

    /**
     * The hash used to search.
     *
     * Keyed with the application key: without it, two deployments would produce
     * the same hash for the same number, and eleven-digit phone numbers make
     * that a trivial rainbow table.
     */
    public static function hashOf(string $phone): string
    {
        $normalized = preg_replace('/\D/', '', $phone) ?? $phone;

        return hash_hmac('sha256', $normalized, (string) config('app.key'));
    }
}
