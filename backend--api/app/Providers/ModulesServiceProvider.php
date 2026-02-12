<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class ModulesServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
    
        $modulesPath = base_path('Modules');

        if (is_dir($modulesPath)) {
            // Obtener todos los directorios dentro de Modules (User, Auth, etc.)
            $modules = array_filter(glob($modulesPath . '/*'), 'is_dir');

            foreach ($modules as $module) {
                // Cargar Rutas (si existe routes.php dentro del módulo)
                if (file_exists($module . '/routes.php')) {
                    $this->loadRoutesFrom($module . '/routes.php');
                }

                // Cargar Migraciones (si existe la carpeta Database/Migrations)
                if (is_dir($module . '/Database/Migrations')) {
                    $this->loadMigrationsFrom($module . '/Database/Migrations');
                }
            }
        }
    }
}
