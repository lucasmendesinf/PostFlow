<?php

namespace App\Services\Instagram;

use Illuminate\Http\Client\Response;
use RuntimeException;

class InstagramApiException extends RuntimeException
{
    /** Códigos da Graph API para token expirado, revogado ou inválido. */
    private const TOKEN_INVALIDO = [102, 190];

    public function __construct(
        string $message,
        public readonly ?int $apiCode = null,
        public readonly ?string $apiType = null,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message, $apiCode ?? 0);
    }

    /**
     * A Meta devolve erros em dois formatos: {"error": {message, type, code}} na
     * graph.instagram.com e {error_type, code, error_message} na api.instagram.com.
     */
    public static function fromResponse(Response $response): self
    {
        $corpo = $response->json() ?? [];
        $erro = $corpo['error'] ?? null;

        if (is_array($erro)) {
            return new self(
                $erro['message'] ?? 'Erro desconhecido da API do Instagram',
                isset($erro['code']) ? (int) $erro['code'] : null,
                $erro['type'] ?? null,
                $response->status(),
            );
        }

        return new self(
            $corpo['error_message'] ?? (is_string($erro) ? $erro : 'Erro HTTP '.$response->status().' da API do Instagram'),
            isset($corpo['code']) ? (int) $corpo['code'] : null,
            $corpo['error_type'] ?? null,
            $response->status(),
        );
    }

    public function isTokenInvalid(): bool
    {
        return in_array($this->apiCode, self::TOKEN_INVALIDO, true);
    }
}
