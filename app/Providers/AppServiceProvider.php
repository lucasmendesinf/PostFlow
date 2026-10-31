<?php

namespace App\Providers;

use App\Services\Instagram\InstagramClient;
use App\Support\Tenancy\TenantContext;
use App\Support\TokenEncrypter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // scoped: zera a cada requisição e a cada job processado pelo worker.
        $this->app->scoped(TenantContext::class);

        $this->app->singleton(TokenEncrypter::class, fn () => new TokenEncrypter(
            config('postflow.token_key'),
            config('postflow.token_cipher'),
        ));

        $this->app->bind(InstagramClient::class, fn () => new InstagramClient(config('services.instagram')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
