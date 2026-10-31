<?php

namespace App\Support;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Encrypter dedicado aos tokens de acesso (AES-256), com chave própria fora do
 * banco. Separado da APP_KEY para que trocar a chave da aplicação não invalide
 * os tokens das contas conectadas.
 */
class TokenEncrypter
{
    private Encrypter $encrypter;

    public function __construct(?string $key, string $cipher = 'AES-256-CBC')
    {
        if (blank($key)) {
            throw new RuntimeException('POSTFLOW_TOKEN_KEY não definida. Gere com: php artisan postflow:token-key');
        }

        if (Str::startsWith($key, 'base64:')) {
            $key = base64_decode(Str::after($key, 'base64:'));
        }

        $this->encrypter = new Encrypter($key, $cipher);
    }

    public function encrypt(string $valor): string
    {
        return $this->encrypter->encryptString($valor);
    }

    public function decrypt(string $cifrado): string
    {
        return $this->encrypter->decryptString($cifrado);
    }

    public static function generateKey(string $cipher = 'AES-256-CBC'): string
    {
        return 'base64:'.base64_encode(Encrypter::generateKey($cipher));
    }
}
