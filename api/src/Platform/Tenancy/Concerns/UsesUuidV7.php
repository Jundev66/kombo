<?php

declare(strict_types=1);

namespace Platform\Tenancy\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

/**
 * UUID **v7** identifiers, not v4.
 *
 * v7 carries the timestamp in the high bits, so ids keep creation order and
 * inserts land at the end of the index instead of scattering across it. Still
 * unguessable from outside, which is what rules out auto-increment: ids travel
 * in URLs.
 */
trait UsesUuidV7
{
    use HasUuids;

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}
