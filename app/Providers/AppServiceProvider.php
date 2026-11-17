<?php

namespace App\Providers;

use App\Models\Task;
use App\Models\TaskSchedule;
use App\Observers\TaskCalendarSyncObserver;
use App\Observers\TaskScheduleCalendarSyncObserver;
use App\Services\Ai\AiProviderRegistry;
use App\Services\Ai\OpenAiProvider;
use App\Services\Lms\LmsAdapterRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->tag([OpenAiProvider::class], 'ai.providers');

        $this->app->singleton(
            AiProviderRegistry::class,
            fn (Container $app): AiProviderRegistry => new AiProviderRegistry($app->tagged('ai.providers')),
        );

        $this->app->singleton(
            LmsAdapterRegistry::class,
            fn (Container $app): LmsAdapterRegistry => new LmsAdapterRegistry($app->tagged('lms.adapters')),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        Task::observe(TaskCalendarSyncObserver::class);
        TaskSchedule::observe(TaskScheduleCalendarSyncObserver::class);

        if ($this->app->runningInConsole()) {
            DevCommands::artisan('queue:listen --tries=3 --timeout=70', 'queue');
            DevCommands::artisan('schedule:work', 'scheduler');
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
