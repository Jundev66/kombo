<?php

declare(strict_types=1);

use Modules\Catalog\Domain\Exceptions\InvalidSelectionRule;
use Modules\Catalog\Domain\ValueObjects\SelectionRule;

/*
 * The rule lives here rather than in a form validation because the till, the
 * portal and the bot have to apply exactly the same one. Three validations are
 * three chances for one to go stale.
 */

it('"pick one" requires exactly one', function (): void {
    $rule = SelectionRule::exactlyOne();

    expect($rule->accepts(0))->toBeFalse()
        ->and($rule->accepts(1))->toBeTrue()
        ->and($rule->accepts(2))->toBeFalse()
        ->and($rule->isRequired())->toBeTrue();
});

it('optional extras accept none', function (): void {
    $rule = SelectionRule::anyNumber(3);

    expect($rule->accepts(0))->toBeTrue()
        ->and($rule->accepts(3))->toBeTrue()
        ->and($rule->accepts(4))->toBeFalse()
        ->and($rule->isRequired())->toBeFalse();
});

it('accepts a range, like picking two or three sides', function (): void {
    $rule = SelectionRule::of(2, 3);

    expect($rule->accepts(1))->toBeFalse()
        ->and($rule->accepts(2))->toBeTrue()
        ->and($rule->accepts(3))->toBeTrue();
});

it('rejects an impossible rule', function (): void {
    expect(fn () => SelectionRule::of(3, 2))->toThrow(InvalidSelectionRule::class)
        ->and(fn () => SelectionRule::of(0, 0))->toThrow(InvalidSelectionRule::class)
        ->and(fn () => SelectionRule::of(-1, 2))->toThrow(InvalidSelectionRule::class);
});

it('explains itself on the screen of whoever is ordering', function (): void {
    expect(SelectionRule::exactlyOne()->explain())->toBe('Elige una opción.')
        ->and(SelectionRule::anyNumber(3)->explain())->toBe('Puedes elegir hasta 3.')
        ->and(SelectionRule::of(2, 3)->explain())->toBe('Elige entre 2 y 3 opciones.');
});
