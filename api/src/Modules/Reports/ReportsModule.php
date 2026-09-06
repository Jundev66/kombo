<?php

declare(strict_types=1);

namespace Modules\Reports;

use Platform\Modules\ModuleManifest;
use Platform\Modules\Setting;

/**
 * What was sold, when, and how it was paid for. Four questions and no more:
 * how much did I sell today, what sells most, what time do people come in, and
 * how do they pay me.
 *
 * There are no margins, and none are invented: computing profit needs
 * per-product costs, which the system does not have. A "profit" computed from
 * nothing would be worse than none — somebody would make decisions with it.
 */
final class ReportsModule extends ModuleManifest
{
    public function code(): string
    {
        return 'reports';
    }

    public function name(): string
    {
        return 'Reportes';
    }

    public function description(): string
    {
        return 'Qué vendiste hoy, qué se vende más y a qué hora entra la gente.';
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
            // Seeing sales, deliberately separate: in some tenants the manager works
            // all day and the owner would rather they did not see the totals.
            'reports.view_sales',
        ];
    }

    /**
     * @return array<string, Setting>
     */
    public function settings(): array
    {
        return [
            // How many products go into "what sells most". Ten reads at a glance on a
            // phone.
            'top_products' => Setting::int(10)->min(3)->max(50),
        ];
    }
}
