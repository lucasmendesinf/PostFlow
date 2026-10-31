<?php

namespace App\Services\Instagram;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Chamadas de OAuth e token da Instagram API com Business Login for Instagram.
 * A publicação (containers, media_publish) entra na etapa 3.
 */
class InstagramClient
{
    private const AUTHORIZE_URL = 'https://www.instagram.com/oauth/authorize';

    private const SHORT_TOKEN_URL = 'https://api.instagram.com/oauth/access_token';

    private const GRAPH_URL = 'https://graph.instagram.com';

    public function __construct(private readonly array $config) {}

    public function authorizationUrl(string $state): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $this->required('client_id'),
            'redirect_uri' => $this->required('redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(',', $this->config['scopes']),
            'state' => $state,
        ]);
    }

    /**
     * Troca o código do callback por um token de curta duração (1 h).
     *
     * @return array{access_token: string, user_id: string}
     */
    public function exchangeCode(string $code): array
    {
        $response = $this->http()->asForm()->post(self::SHORT_TOKEN_URL, [
            'client_id' => $this->required('client_id'),
            'client_secret' => $this->required('client_secret'),
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->required('redirect_uri'),
            // A Meta anexa "#_" ao código no redirecionamento.
            'code' => preg_replace('/#_$/', '', $code),
        ]);

        // Formato antigo: {access_token, user_id}; atual: {data: [{access_token, user_id, permissions}]}.
        $dados = $this->json($response);
        $dados = $dados['data'][0] ?? $dados;

        return [
            'access_token' => (string) $dados['access_token'],
            'user_id' => (string) $dados['user_id'],
        ];
    }

    /**
     * Token curto -> token de longa duração (60 dias).
     *
     * @return array{access_token: string, expires_in: int}
     */
    public function exchangeForLongLived(string $shortToken): array
    {
        return $this->tokenResponse($this->http()->get(self::GRAPH_URL.'/access_token', [
            'grant_type' => 'ig_exchange_token',
            'client_secret' => $this->required('client_secret'),
            'access_token' => $shortToken,
        ]));
    }

    /**
     * Renova um token longo por mais 60 dias. A Meta só aceita tokens com pelo
     * menos 24 h de idade e ainda não vencidos.
     *
     * @return array{access_token: string, expires_in: int}
     */
    public function refresh(string $longToken): array
    {
        return $this->tokenResponse($this->http()->get(self::GRAPH_URL.'/refresh_access_token', [
            'grant_type' => 'ig_refresh_token',
            'access_token' => $longToken,
        ]));
    }

    /**
     * Perfil da conta. "user_id" é o IG ID usado nas rotas de publicação.
     *
     * @return array{user_id: string, username: string}
     */
    public function me(string $token): array
    {
        $dados = $this->json($this->http()->get(self::GRAPH_URL.'/'.$this->config['graph_version'].'/me', [
            'fields' => 'user_id,username',
            'access_token' => $token,
        ]));

        return [
            'user_id' => (string) ($dados['user_id'] ?? $dados['id']),
            'username' => (string) $dados['username'],
        ];
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->timeout(20)->connectTimeout(10);
    }

    /** @return array{access_token: string, expires_in: int} */
    private function tokenResponse(Response $response): array
    {
        $dados = $this->json($response);

        return [
            'access_token' => (string) $dados['access_token'],
            'expires_in' => (int) $dados['expires_in'],
        ];
    }

    private function json(Response $response): array
    {
        if ($response->failed() || isset($response->json()['error'])) {
            throw InstagramApiException::fromResponse($response);
        }

        return $response->json();
    }

    private function required(string $chave): string
    {
        $valor = $this->config[$chave] ?? null;

        if (blank($valor)) {
            throw new RuntimeException("Configuração do Instagram ausente: services.instagram.{$chave}. Veja INSTAGRAM_* no .env.");
        }

        return $valor;
    }
}
