<?php

declare(strict_types=1);

namespace Modules\Documents;

use Platform\Modules\ModuleManifest;
use Platform\Modules\Setting;

/**
 * The documents handed to the customer. Today one: the delivery note.
 *
 * A commercial document, not a fiscal one — the paper says so — and it neither
 * replaces an invoice nor removes the tenant's tax obligations.
 */
final class DocumentsModule extends ModuleManifest
{
    public function code(): string
    {
        return 'documents';
    }

    public function name(): string
    {
        return 'Notas de entrega';
    }

    public function description(): string
    {
        return 'El papel que se le entrega al cliente con lo que se llevó y cuánto pagó.';
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
            'notes.issue',
            'notes.reprint',

            // No `notes.void`: with one note per order and no reissue, voiding the
            // paper is voiding the whole sale, so that permission lives at the till.
        ];
    }

    /**
     * @return array<string, Setting>
     */
    public function settings(): array
    {
        return [
            // What goes below the total: hours, address, "thank you for your custom".
            'footer_text' => Setting::text('')->maxLength(300),

            // Whether menu prices include tax. Here it only affects how the total is
            // shown on paper: this system does NOT compute VAT as a fiscal debit.
            'prices_include_tax' => Setting::bool(true),
        ];
    }
}
