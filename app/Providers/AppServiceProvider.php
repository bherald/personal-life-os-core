<?php

namespace App\Providers;

use App\Services\EmailClassificationService;
use App\Services\EmailRateLimitService;
use App\Services\EmailService;
use App\Services\ThunderbirdService;
use App\Services\UnifiedCli\CliWorkflowExecutionScope;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

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
        // Laravel dispatches this before resolving/flushing the cache store.
        // Cache stores contain live custody; use named release invalidation.
        Event::listen('cache:clearing', static function (): never {
            throw new \RuntimeException('Global cache clearing is held to preserve execution custody. Use ops:release-cache-invalidate instead.');
        });

        // Laravel carries hidden context across queue serialization; ordinary
        // PLOS jobs without a CLI scope keep their existing execution behavior.
        Bus::pipeThrough([CliWorkflowExecutionScope::class]);

        Passport::tokensCan([
            'mcp.tools.invoke' => 'Invoke configured MCP tools under the existing server policy.',
            'mcp.servers.manage' => 'Manage existing MCP server settings and cache.',
            'ops.status.read' => 'Read content-free PLOS offline operational status.',
            'cyber.jobs.read' => 'Read only the authenticated owner’s durable CYBER task status.',
            'cyber.jobs.submit' => 'Submit the qualified synthetic CYBER task after explicit approval.',
            'cyber.jobs.cancel' => 'Request cooperative cancellation of the owner’s CYBER task.',
            'cyber.jobs.resume' => 'Resume the owner’s exact approved CYBER checkpoint; unknown effects remain held.',
        ]);

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
