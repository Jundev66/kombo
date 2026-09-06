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
    public function register(): void {}

    public function boot(): void
    {
        // Ours, with `tenant_id` and RLS: one tenant's token is no good in another.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Outside production, assigning an attribute that does not exist blows up
        // instead of being discarded silently. A typo in a column name is among the
        // most expensive bugs to find when it fails quietly.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Immutable dates: `$date->addDay()` returning a new object heads off a
        // whole class of bug in expiry and kitchen-timing arithmetic.
        Date::use(CarbonImmutable::class);
    }
}
