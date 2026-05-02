<?php

namespace App\Providers;

use App\Services\EmailClassificationService;
use App\Services\EmailRateLimitService;
use App\Services\EmailService;
use App\Services\ThunderbirdService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register EmailService with Thunderbird MCP + classification + rate limiting
        $this->app->singleton(EmailService::class, function ($app) {
            return new EmailService(
                $app->make(ThunderbirdService::class),
                $app->make(EmailClassificationService::class),
                null, // bounceService removed (D1: Thunderbird handles bounces)
                $app->make(EmailRateLimitService::class)
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // SAFETY: Block migrate:fresh, migrate:reset, db:wipe on production.
        // These commands wiped 229 tables on 2026-03-30. Never again.
        if ($this->app->isProduction()) {
            DB::prohibitDestructiveCommands();
        }

        // Dynamic URL detection - works with localhost, .226, .227, or any access point
        // Only force APP_URL when running in console (CLI/artisan/queue)
        if ($this->app->runningInConsole()) {
            URL::forceRootUrl(config('app.url'));
            URL::forceScheme(parse_url(config('app.url'), PHP_URL_SCHEME) ?? 'http');
        }
        // For web requests, Laravel automatically detects the correct URL from the HTTP request
    }
}
