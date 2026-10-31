<?php

namespace App\Console\Commands;

use App\Support\TokenEncrypter;
use Illuminate\Console\Command;

class GenerateTokenKeyCommand extends Command
{
    protected $signature = 'postflow:token-key {--show : Só exibe a chave, sem gravar no .env}';

    protected $description = 'Gera a chave AES-256 usada para criptografar os tokens do Instagram (POSTFLOW_TOKEN_KEY)';

    public function handle(): int
    {
        $chave = TokenEncrypter::generateKey(config('postflow.token_cipher'));

        if ($this->option('show')) {
            $this->line($chave);

            return self::SUCCESS;
        }

        $arquivo = $this->laravel->environmentFilePath();
        $conteudo = file_get_contents($arquivo);

        if (preg_match('/^POSTFLOW_TOKEN_KEY=.+$/m', $conteudo)) {
            $this->error('POSTFLOW_TOKEN_KEY já está definida. Trocar a chave invalida os tokens gravados; reconecte as contas se fizer isso.');

            return self::FAILURE;
        }

        $conteudo = preg_match('/^POSTFLOW_TOKEN_KEY=$/m', $conteudo)
            ? preg_replace('/^POSTFLOW_TOKEN_KEY=$/m', 'POSTFLOW_TOKEN_KEY='.$chave, $conteudo)
            : rtrim($conteudo).PHP_EOL.'POSTFLOW_TOKEN_KEY='.$chave.PHP_EOL;

        file_put_contents($arquivo, $conteudo);
        $this->info('POSTFLOW_TOKEN_KEY gravada no .env.');

        return self::SUCCESS;
    }
}
