<?php

declare(strict_types=1);

namespace App\Models\Documents;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * A delivery note. It is not an invoice.
 */
#[Fillable([
    'order_id', 'series', 'number', 'issued_at', 'issued_by', 'issued_by_name',
    'customer_name', 'customer_tax_id',
    'subtotal_cents', 'total_cents', 'currency', 'exchange_rate', 'snapshot',
    'printed_count', 'voided_at', 'voided_by', 'void_reason',
])]
class DeliveryNoteModel extends Model
{
    use BelongsToTenant, UsesUuidV7;

    protected $table = 'delivery_notes';

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'subtotal_cents' => 'integer',
            'total_cents' => 'integer',
            'printed_count' => 'integer',
            'snapshot' => 'array',
            'issued_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    /** "A-000042" — how it reads on paper and how it is searched for. */
    public function reference(): string
    {
        return sprintf('%s-%06d', $this->series, $this->number);
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
