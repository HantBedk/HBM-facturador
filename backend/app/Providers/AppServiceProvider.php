<?php

namespace App\Providers;

use App\Models\AppSetting;
use App\Models\CompanyRecurringService;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mime\Address;

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

        Event::listen(MessageSending::class, function (MessageSending $event): void {
            $row = AppSetting::query()->where('key', AppSetting::KEY_MAIL_NOTIFICATIONS_FROM)->first();
            if ($row === null || ! is_array($row->value)) {
                return;
            }
            $addr = trim((string) ($row->value['address'] ?? ''));
            if ($addr === '' || ! filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                return;
            }
            $name = trim((string) ($row->value['name'] ?? ''));
            $event->message->from(new Address($addr, $name !== '' ? $name : (string) config('app.name', 'HBM')));
        });
    }
}
