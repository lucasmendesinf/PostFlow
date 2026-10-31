<?php

namespace App\Services\Instagram;

use App\Enums\AccountStatus;
use App\Models\InstagramAccount;
use App\Notifications\InstagramReconnectNeeded;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class InstagramAccountService
{
    public function __construct(
        private readonly InstagramClient $client,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * Fluxo do callback: código -> token curto -> token longo -> perfil -> grava.
     * Reconectar uma conta existente atualiza o token e volta o status para ativo.
     */
    public function connect(int $tenantId, string $code): InstagramAccount
    {
        $curto = $this->client->exchangeCode($code);
        $longo = $this->client->exchangeForLongLived($curto['access_token']);
        $perfil = $this->client->me($longo['access_token']);

        return $this->tenancy->run($tenantId, fn () => InstagramAccount::updateOrCreate(
            ['tenant_id' => $tenantId, 'ig_user_id' => $perfil['user_id']],
            [
                'username' => $perfil['username'],
                'access_token' => $longo['access_token'],
                'token_expires_at' => now()->addSeconds($longo['expires_in']),
                'status' => AccountStatus::Ativo,
            ],
        ));
    }

    /**
     * Renova o token longo. Token inválido/revogado ou já vencido marca a conta
     * como "reconectar"; outros erros (rede, throttling) sobem para o job tentar de novo.
     */
    public function refresh(InstagramAccount $conta): InstagramAccount
    {
        if ($conta->token_expires_at?->isPast()) {
            $this->markReconnect($conta, 'O token venceu antes de ser renovado.');

            throw new InstagramApiException('Token vencido; a conta precisa ser reconectada.', 190);
        }

        try {
            $novo = $this->client->refresh($conta->access_token);
        } catch (InstagramApiException $e) {
            if ($e->isTokenInvalid()) {
                $this->markReconnect($conta, $e->getMessage());
            }

            throw $e;
        }

        $conta->forceFill([
            'access_token' => $novo['access_token'],
            'token_expires_at' => now()->addSeconds($novo['expires_in']),
            'status' => AccountStatus::Ativo,
        ])->save();

        return $conta;
    }

    public function markReconnect(InstagramAccount $conta, string $motivo): void
    {
        if ($conta->status === AccountStatus::Reconectar) {
            return;
        }

        $conta->forceFill(['status' => AccountStatus::Reconectar])->save();

        Log::warning('Conta do Instagram precisa ser reconectada', [
            'account_id' => $conta->id,
            'tenant_id' => $conta->tenant_id,
            'username' => $conta->username,
            'motivo' => $motivo,
        ]);

        $usuarios = $conta->tenant->users()->get();
        if ($usuarios->isNotEmpty()) {
            Notification::send($usuarios, new InstagramReconnectNeeded($conta, $motivo));
        }
    }
}
