<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Modules\GPS\Models\Device;
use App\Modules\GPS\Observers\DeviceObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if (class_exists(\L5Swagger\L5SwaggerServiceProvider::class)) {
        $this->app->register(\L5Swagger\L5SwaggerServiceProvider::class);
        }
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //Registrar el observer para el modelo Device
        Device::observe(DeviceObserver::class);
    }
}
