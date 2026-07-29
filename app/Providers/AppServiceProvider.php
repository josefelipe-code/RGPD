<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Bandeja\ImapProvider;
use App\Services\Bandeja\WebklexImapProvider;
use App\Services\MailAccountConfigService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    /** Registra bindings de aplicación durante el arranque del contenedor. */
    public function register(): void
    {
        $this->app->singleton(MailAccountConfigService::class);
        $this->app->bind(ImapProvider::class, WebklexImapProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    /** Configura valores y autorizaciones globales durante el arranque de Laravel. */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    /** Define valores predeterminados de autorización y configuración de la aplicación. */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // La closure concede el bypass global únicamente a administradores.
        Gate::before(function (User $user): ?bool {
            return $user->hasRole('Super Administrador') ? true : null;
        });

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
