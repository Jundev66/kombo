<?php

declare(strict_types=1);

namespace Modules\Catalog\Interfaces\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SHAPE validation, not business rules.
 *
 * Whether the price is non-negative or the stock adds up is the domain's call:
 * those rules also have to hold for an Excel importer or a seeder, where there
 * is no `FormRequest` at all.
 */
final class StoreProductRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],

            // In CENTS. Never a decimal: `12.30` in floating point is not 12.30, and
            // that error ends up in a cash count that does not balance.
            'price_cents' => ['required', 'integer', 'min:0'],

            'category_id' => ['nullable', 'uuid'],
            'description' => ['nullable', 'string', 'max:2000'],
            'photo_url' => ['nullable', 'string', 'max:500'],
            'prep_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'track_stock' => ['boolean'],
            'stock_qty' => ['nullable', 'integer', 'min:0'],
            'modifier_group_ids' => ['array'],
            'modifier_group_ids.*' => ['uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Ponle un nombre al producto.',
            'price_cents.required' => 'Ponle un precio.',
            'price_cents.min' => 'El precio no puede ser negativo.',
            'prep_minutes.max' => 'Ese tiempo de preparación parece un error de tecleo.',
        ];
    }
}
