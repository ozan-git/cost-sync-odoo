<?php

namespace App\Providers;

use App\Services\Odoo\OdooClientInterface;
use App\Services\Odoo\OdooFakeClient;
use App\Services\Odoo\OdooRestClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OdooClientInterface::class, function () {
            $config = config('services.odoo');

            return ($config['simulate'] ?? true)
                ? new OdooFakeClient($config)
                : new OdooRestClient($config);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
