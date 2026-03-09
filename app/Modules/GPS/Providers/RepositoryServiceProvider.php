<?php

namespace App\Modules\GPS\Providers;

use Illuminate\Support\ServiceProvider;
use App\Modules\GPS\Repositories\Contracts\DeviceRepositoryInterface;
use App\Modules\GPS\Repositories\Eloquent\DeviceRepository;
use App\Modules\GPS\Repositories\Contracts\PositionRepositoryInterface;
use App\Modules\GPS\Repositories\Eloquent\PositionRepository;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //Cada vez que un controlador pida DeviceRepositoryInterface, Laravel inyectará DeviceRepository
        $this->app->bind(DeviceRepositoryInterface::class, DeviceRepository::class);
        $this->app->bind(PositionRepositoryInterface::class, PositionRepository::class);
    }
    public function boot(): void
    {
        //
    }
}