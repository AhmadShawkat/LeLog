<?php

namespace App\Providers;

use App\Configuration\ConfigurationValidator;
use App\Lifecycle\ApplicationLifecycle;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ApplicationLifecycle::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(ConfigurationValidator $configuration): void
    {
        $configuration->validate();
    }
}
