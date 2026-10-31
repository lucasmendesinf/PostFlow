<?php

namespace App\Jobs\Instagram;

use App\Enums\AccountStatus;
use App\Models\InstagramAccount;
use App\Services\Instagram\InstagramAccountService;
use App\Services\Instagram\InstagramApiException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Renova o token de uma conta. Roda na fila "publish", a única que fala com a Meta. */
class RefreshInstagramTokenJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Espera crescente entre tentativas (segundos). */
    public array $backoff = [60, 300, 900];

    public function __construct(public int $accountId)
    {
        $this->onQueue(config('postflow.queues.publish'));
    }

    public function uniqueId(): string
    {
        return (string) $this->accountId;
    }

    public function handle(InstagramAccountService $contas): void
    {
        $conta = InstagramAccount::find($this->accountId);

        if (! $conta || $conta->status !== AccountStatus::Ativo) {
            return;
        }

        try {
            $contas->refresh($conta);
        } catch (InstagramApiException $e) {
            // Token inválido não melhora com nova tentativa: a conta já foi marcada.
            if ($e->isTokenInvalid()) {
                $this->fail($e);

                return;
            }

            throw $e;
        }
    }

    /** Esgotou as tentativas (rede, throttling, erro da Meta): pede reconexão. */
    public function failed(?Throwable $e): void
    {
        $conta = InstagramAccount::find($this->accountId);

        if ($conta) {
            app(InstagramAccountService::class)->markReconnect($conta, $e?->getMessage() ?? 'Falha ao renovar o token.');
        }
    }
}
