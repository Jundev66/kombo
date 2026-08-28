<?php

declare(strict_types=1);

use Modules\Catalog\Domain\Exceptions\InvalidSelectionRule;
use Modules\Catalog\Domain\ValueObjects\SelectionRule;

/*
 * La regla vive aquí y no en la validación del formulario porque la caja, el
 * portal y el bot tienen que aplicar exactamente la misma. Tres validaciones
 * distintas son tres oportunidades de que una se quede vieja.
 */

it('«elige uno» exige exactamente uno', function (): void {
    $regla = SelectionRule::exactlyOne();

    expect($regla->accepts(0))->toBeFalse()
        ->and($regla->accepts(1))->toBeTrue()
        ->and($regla->accepts(2))->toBeFalse()
        ->and($regla->isRequired())->toBeTrue();
});

it('los extras opcionales aceptan ninguno', function (): void {
    $regla = SelectionRule::anyNumber(3);

    expect($regla->accepts(0))->toBeTrue()
        ->and($regla->accepts(3))->toBeTrue()
        ->and($regla->accepts(4))->toBeFalse()
        ->and($regla->isRequired())->toBeFalse();
});

it('acepta un rango, como elegir dos o tres acompañantes', function (): void {
    $regla = SelectionRule::of(2, 3);

    expect($regla->accepts(1))->toBeFalse()
        ->and($regla->accepts(2))->toBeTrue()
        ->and($regla->accepts(3))->toBeTrue();
});

it('rechaza una regla imposible', function (): void {
    expect(fn () => SelectionRule::of(3, 2))->toThrow(InvalidSelectionRule::class)
        ->and(fn () => SelectionRule::of(0, 0))->toThrow(InvalidSelectionRule::class)
        ->and(fn () => SelectionRule::of(-1, 2))->toThrow(InvalidSelectionRule::class);
});

it('sabe explicarse en la pantalla de quien está pidiendo', function (): void {
    expect(SelectionRule::exactlyOne()->explain())->toBe('Elige una opción.')
        ->and(SelectionRule::anyNumber(3)->explain())->toBe('Puedes elegir hasta 3.')
        ->and(SelectionRule::of(2, 3)->explain())->toBe('Elige entre 2 y 3 opciones.');
});
