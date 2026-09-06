<?php

declare(strict_types=1);

/*
 * The most important tests in the system.
 *
 * The question they answer is not "is it isolated?" but:
 *
 *      WHAT HAS TO FAIL FOR ANOTHER TENANT'S DATA TO LEAK?
 *
 * Which is why several deliberately disable one layer of defence and check that
 * the next holds alone. Isolation that only works when everything is fine is
 * not isolation, it is luck.
 *
 * They run as `kombo_app`, the user WITHOUT BYPASSRLS. As the schema owner they
 * would pass green with RLS completely broken — the worst kind of green.
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    // Random suffix: seeding is additive, and these tests cannot depend on a
    // freshly created database.
    $suffix = Str::lower(Str::random(6));

    $this->arepera = makeTenant("elsazon-{$suffix}");
    $this->pizzeria = makeTenant("laesquina-{$suffix}");

    actingForTenant($this->arepera);
    $this->areperaUser = makeUser($this->arepera, "maria-{$suffix}@ejemplo.com");

    actingForTenant($this->pizzeria);
    $this->pizzeriaUser = makeUser($this->pizzeria, "pedro-{$suffix}@ejemplo.com");
});

it('each tenant sees only its own data', function (): void {
    actingForTenant($this->arepera);
    expect(DB::table('users')->pluck('id')->all())->toBe([$this->areperaUser]);

    actingForTenant($this->pizzeria);
    expect(DB::table('users')->pluck('id')->all())->toBe([$this->pizzeriaUser]);
});

it('does not find another tenant\'s row even when asked for by id', function (): void {
    // The realistic case: somebody copies an id from a URL and tries it on
    // another account. It has to answer "does not exist" — a 403 would already
    // confirm the resource exists.
    actingForTenant($this->arepera);

    expect(DB::table('users')->find($this->pizzeriaUser))->toBeNull();
});

it('a direct database query does not see another tenant\'s data either', function (): void {
    // No Eloquent, no global scope, no model. Here PostgreSQL does the
    // filtering, not the framework.
    actingForTenant($this->arepera);

    $emails = DB::select('select email from users');

    expect($emails)->toHaveCount(1);
});

it('a request with NO tenant returns NO rows, not all of them', function (): void {
    // The difference between these two answers is the difference between a
    // system that fails and one that leaks every customer's data. The failure
    // mode has to be DENY.
    withoutTenant();

    expect(DB::table('users')->count())->toBe(0);
});

it('the database refuses to write a row in another tenant\'s name', function (): void {
    // The WITH CHECK test, deliberately in raw SQL: through Eloquent it would
    // never get here, because the trait fills the tenant in. What is checked is
    // what happens if that trait fails or somebody bypasses it.
    actingForTenant($this->arepera);

    expect(fn () => DB::table('users')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->pizzeria,   // ← ANOTHER tenant's
        'name' => 'Colada',
        'email' => 'colada@ejemplo.com',
        'password' => 'x',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('another tenant\'s row cannot be stolen by changing its tenant', function (): void {
    // The other side of WITH CHECK: moving one's own row to another tenant.
    actingForTenant($this->arepera);

    expect(fn () => DB::table('users')
        ->where('id', $this->areperaUser)
        ->update(['tenant_id' => $this->pizzeria]))
        ->toThrow(QueryException::class);
});

it('deleting another tenant\'s row deletes nothing', function (): void {
    // It does not error — it simply does not see the row — and that is right:
    // the attacker does not even learn it exists.
    actingForTenant($this->arepera);

    $deletedRows = DB::table('users')->where('id', $this->pizzeriaUser)->delete();

    expect($deletedRows)->toBe(0);

    actingForTenant($this->pizzeria);
    expect(DB::table('users')->find($this->pizzeriaUser))->not->toBeNull();
});

it('isolation holds even with the context left as an empty string', function (): void {
    // How a connection is left after cleaning it before the pool.
    // `current_setting` would return '' and without the nullif() the uuid cast
    // would blow up with a SQL error rather than returning zero rows.
    DB::statement("select set_config('app.tenant_id', '', false)");

    expect(DB::table('users')->count())->toBe(0);
});
