<?php

declare(strict_types=1);

/*
 * The audit log cannot be edited — not because nobody wrote the code to do it,
 * but because the application's PostgreSQL user has INSERT and SELECT on this
 * table and nothing else.
 *
 * The only place where a database privilege does a job the code cannot, and the
 * second reason there are two database users.
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->tenant = makeTenant('bitacora-'.Str::lower(Str::random(6)));
    actingForTenant($this->tenant);

    $this->entryId = (string) Str::uuid7();

    DB::table('audit_log')->insert([
        'id' => $this->entryId,
        'tenant_id' => $this->tenant,
        'user_name' => 'Ana',
        'action' => 'orders.void',
        'reason' => 'El cliente se arrepintió',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('can be inserted into and read', function (): void {
    $entry = DB::table('audit_log')->find($this->entryId);

    expect($entry?->action)->toBe('orders.void')
        ->and($entry?->user_name)->toBe('Ana');
});

it('CANNOT be modified, even if the code tries', function (): void {
    // The case this prevents is concrete: somebody with access to the
    // application changing a void's reason after it is questioned.
    expect(fn () => DB::table('audit_log')
        ->where('id', $this->entryId)
        ->update(['reason' => 'Otra cosa']))
        ->toThrow(QueryException::class);
});

it('CANNOT be deleted, even if the code tries', function (): void {
    expect(fn () => DB::table('audit_log')->where('id', $this->entryId)->delete())
        ->toThrow(QueryException::class);
});

it('still cannot be bulk deleted', function (): void {
    expect(fn () => DB::table('audit_log')->delete())
        ->toThrow(QueryException::class);
});
