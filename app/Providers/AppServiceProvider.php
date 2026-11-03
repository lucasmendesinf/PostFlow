<?php

namespace App\Providers;

use App\Contracts\GeraConteudo;
use App\Contracts\Renderiza;
use App\Services\Conteudo\ClaudeGerador;
use App\Services\Instagram\InstagramClient;
use App\Services\Instagram\InstagramPublisher;
use App\Services\Render\Renderizador;
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
        $this->app->bind(InstagramPublisher::class, fn () => new InstagramPublisher(config('services.instagram')));
        $this->app->bind(GeraConteudo::class, fn () => new ClaudeGerador(config('postflow.claude')));
        $this->app->bind(Renderiza::class, fn () => new Renderizador(config('postflow.services')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
