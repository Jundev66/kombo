<?php

declare(strict_types=1);

namespace Modules\Customers;

use Platform\Modules\ModuleManifest;

/**
 * Who buys. The record fills itself in from every order with a phone number —
 * in a food business nobody fills in a customer form between two lunches.
 *
 * Switched off wholesale, which makes sense for a street stall.
 */
final class CustomersModule extends ModuleManifest
{
    public function code(): string
    {
        return 'customers';
    }

    public function name(): string
    {
        return 'Clientes';
    }

    public function description(): string
    {
        return 'Quién compra, cuánto lleva gastado y cuándo fue la última vez.';
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return ['orders'];
    }

    public function routes(): ?string
    {
        return __DIR__.'/Interfaces/Http/Routes/api.php';
    }

    public function migrations(): ?string
    {
        return __DIR__.'/Infrastructure/Migrations';
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return [
            'customers.view',
            // A note: "no onion for them", "always pays cash". It is what makes the
            // record worth having.
            'customers.manage',
        ];
    }
}
