<?php

namespace App\Providers;

use Carbon\Carbon;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Vite;
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
        Carbon::setLocale('pt_BR');
        Paginator::useBootstrapFive();

        if (filter_var(env('PLAYWRIGHT_TEST', false), FILTER_VALIDATE_BOOL)) {
            Vite::useHotFile(storage_path('framework/playwright.hot'));
        }
    }
}
