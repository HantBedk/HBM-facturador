<?php

namespace App\Providers;

use App\Models\CompanyRecurringService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (file_exists('/.dockerenv')) {
            $dir = '/tmp/laravel-views';
            if (! is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            // Refuerzo si bootstrap/cache/config.php se generó sin /.dockerenv (p. ej. en el host Windows).
            config(['view.compiled' => $dir]);
        }

        Route::model('recurring_service', CompanyRecurringService::class);
    }
}
