<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\PersonalAccessToken;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // El nuestro, con `tenant_id` y RLS: un token de un negocio no sirve
        // en otro.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Fuera de producción, asignar un atributo que no existe revienta en
        // vez de descartarse en silencio. Un typo en un nombre de columna es
        // de los errores más caros de encontrar cuando falla callado.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Fechas inmutables: `$fecha->addDay()` devolviendo un objeto nuevo en
        // vez de mutar el original evita una clase entera de errores en
        // cálculos de vencimiento y de tiempos de cocina.
        Date::use(CarbonImmutable::class);
    }
}
