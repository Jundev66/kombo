<?php

declare(strict_types=1);

namespace Platform\Audit;

/**
 * Who authorised an action with their PIN.
 *
 * Different from `Actor`: the actor started it (the cashier), this allowed it
 * (the manager). Both go into the log, because the useful answer to "who voided
 * that sale?" has two names in it.
 */
final readonly class AuthorizedBy
{
    public function __construct(
        public string $userId,
        public string $userName,
    ) {}
}
