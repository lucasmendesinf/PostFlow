<?php

namespace App\Services\Instagram;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Parâmetro "state" do OAuth: amarra o callback ao tenant que iniciou a conexão
 * e impede CSRF. Uso único, expira em 15 minutos. Não depende de sessão, então
 * funciona tanto pelo painel quanto por um link gerado no terminal.
 */
class OAuthState
{
    private const TTL_MINUTOS = 15;

    public function issue(int $tenantId): string
    {
        $state = Str::random(40);
        Cache::put($this->key($state), $tenantId, now()->addMinutes(self::TTL_MINUTOS));

        return $state;
    }

    /** Devolve o tenant do state e o invalida; nulo se inválido ou expirado. */
    public function consume(string $state): ?int
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $state)) {
            return null;
        }

        $tenantId = Cache::pull($this->key($state));

        return $tenantId === null ? null : (int) $tenantId;
    }

    private function key(string $state): string
    {
        return "instagram-oauth-state:{$state}";
    }
}
