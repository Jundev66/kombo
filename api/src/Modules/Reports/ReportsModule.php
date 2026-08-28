<?php

declare(strict_types=1);

namespace Modules\Reports;

use Platform\Modules\ModuleManifest;
use Platform\Modules\Setting;

/**
 * Qué se vendió, cuándo y cómo se pagó.
 *
 * Cuatro preguntas y ninguna más, porque son las que un dueño de comida se hace
 * de verdad: **cuánto vendí hoy**, **qué se vende más**, **a qué hora entra la
 * gente** y **cómo me pagan**. Con eso decide qué comprar mañana, a qué hora
 * poner a alguien más, y si le sigue conviniendo aceptar pago móvil.
 *
 * **Márgenes no hay, y no se inventan.** Calcular ganancia exige saber lo que
 * cuesta cada plato, y eso el sistema no lo sabe: no hay costo por producto ni
 * recetas. Enseñar una «ganancia» calculada sobre la nada sería peor que no
 * enseñarla — alguien tomaría decisiones con ella. El día que existan costos,
 * este módulo crece; hasta entonces dice lo que sabe.
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
            // Ver las ventas. Va aparte del resto a propósito: hay negocios
            // donde el encargado opera todo el día y el dueño prefiere que no
            // vea los totales.
            'reports.view_sales',
        ];
    }

    /**
     * @return array<string, Setting>
     */
    public function settings(): array
    {
        return [
            // Cuántos productos entran en el «lo que más se vende». Diez es lo
            // que se lee de un vistazo en un teléfono.
            'top_products' => Setting::int(10)->min(3)->max(50),
        ];
    }
}
