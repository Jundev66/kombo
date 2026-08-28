<?php

declare(strict_types=1);

namespace Modules\Catalog\Interfaces\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de FORMA, no de reglas de negocio.
 *
 * Aquí sólo se comprueba que lo que llegó tiene la pinta correcta —que el
 * precio es un entero, que el id parece un uuid—. Que el precio no sea
 * negativo o que el stock cuadre lo decide el dominio, porque esas reglas
 * también tienen que valer cuando el producto entra por un importador de Excel
 * o por una semilla, donde no hay `FormRequest` ninguno.
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

            // En CENTAVOS. Nunca un decimal: `12.30` en coma flotante no es
            // 12,30 y ese error acaba en un cuadre de caja que no cierra.
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
