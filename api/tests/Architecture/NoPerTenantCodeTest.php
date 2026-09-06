<?php

declare(strict_types=1);

/*
 * The golden rule: code is NEVER written for a single tenant.
 *
 * It is the only way one person serves a hundred customers without breaking.
 * Faced with "customer X needs…", there are four steps, stopping at the first
 * that resolves it:
 *
 *   1. Already configurable?   Nothing to do; show them where.
 *   2. Could be an option?     A `Setting` whose default equals today's
 *                              behaviour. Everyone gets it, nobody notices.
 *   3. Could be a switch?      A boolean `Setting`, false by default.
 *   4. Could be a module?      Manifest, tables, use cases, routes.
 *
 * If it fits none of them the answer is NO, with the reason up front. It is
 * never "a developer tweaks this one customer". This test is what stops that
 * option existing.
 */

use Tests\Support\SourceScanner;

it('does not compare against a specific tenant\'s id', function (): void {
    $offenders = [];

    foreach (SourceScanner::files() as $file) {
        $contents = (string) file_get_contents($file);

        // A literal UUID compared against something called tenant.
        if (preg_match('/tenant(_?id)?\s*(===?|!==?|,)\s*[\'"][0-9a-f]{8}-[0-9a-f]{4}-/i', $contents)) {
            $offenders[] = SourceScanner::relative($file).' compara contra un UUID de negocio literal';
        }

        // where('slug', 'elsazon') and relatives.
        if (preg_match('/->where\(\s*[\'"](slug|subdomain)[\'"]\s*,\s*[\'"][a-z0-9-]+[\'"]/i', $contents)) {
            $offenders[] = SourceScanner::relative($file).' busca un negocio por su slug literal';
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'Hay código escrito para UN negocio concreto:',
        ...$offenders,
        '',
        'Sube por los cuatro escalones: ¿ya es configurable? ¿puede ser una',
        'opción? ¿un interruptor? ¿un módulo? Si no cabe en ninguno, la',
        'respuesta es no.',
    ]));
});

it('no model leaves tenant_id mass assignable', function (): void {
    // `Model::create($request->all())` with an assignable `tenant_id` is an
    // HTTP request writing a row in another tenant's name. RLS would reject it,
    // but as a cryptic 500 rather than simply not existing.
    // Both declarations are accepted: Laravel 13's `#[Fillable([...])]` and the
    // classic `$fillable`. What is NOT accepted is a model saying nothing — the
    // silent default is the dangerous one.
    $offenders = [];

    foreach (SourceScanner::files(['app/Models']) as $file) {
        $contents = (string) file_get_contents($file);
        $name = SourceScanner::relative($file);

        /*
         * PLATFORM models are out, and that is not a hole.
         *
         * The rule exists for tenant models, where `BelongsToTenant` fills
         * `tenant_id` from context. `subscriptions` and friends are global
         * tables with no RLS, and the platform decides whose a subscription is
         * — there is no context to take it from.
         *
         * Told apart by the trait rather than a list of names, so a new tenant
         * model joins the rule without anyone remembering to add it.
         */
        if (! str_contains($contents, 'BelongsToTenant')) {
            continue;
        }

        $declaresProperty = (bool) preg_match('/\$fillable\s*=\s*\[/', $contents);
        $declaresAttribute = (bool) preg_match('/#\[Fillable\(/', $contents);

        if (! $declaresProperty && ! $declaresAttribute) {
            $offenders[] = "{$name} no declara qué es asignable (#[Fillable] o \$fillable)";

            continue;
        }

        if (preg_match('/(#\[Fillable\(|\$fillable\s*=\s*)\[[^\]]*[\'"]tenant_id[\'"]/s', $contents)) {
            $offenders[] = "{$name} deja tenant_id asignable";
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'Un modelo deja tenant_id al alcance de la asignación en masa:',
        ...$offenders,
        '',
        'El trait BelongsToTenant lo rellena solo al crear. No hace falta que',
        'sea asignable, y que lo sea es un agujero.',
    ]));
});

it('tenant code does not bypass the tenant filter', function (): void {
    // `acrossTenants()` exists — the platform dashboard and the isolation tests
    // need it — but inside a module it has no legitimate reason to be.
    $offenders = [];

    foreach (SourceScanner::files(['src/Modules']) as $file) {
        if (preg_match('/acrossTenants\(|withoutGlobalScope\(\s*TenantScope/', (string) file_get_contents($file))) {
            $offenders[] = SourceScanner::relative($file);
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'Un módulo se salta el filtro por negocio:',
        ...$offenders,
        '',
        'Eso sólo lo puede hacer la plataforma (el panel interno) y las',
        'pruebas de aislamiento. Desde un módulo, nunca.',
    ]));
});
