<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;

class InstagramConnectUrlCommand extends Command
{
    protected $signature = 'postflow:instagram-connect-url
        {tenant : Id ou nome do tenant}
        {--minutes=30 : Validade do link}';

    protected $description = 'Gera um link assinado para conectar uma conta do Instagram a um tenant (fase 1, sem painel)';

    public function handle(): int
    {
        $busca = $this->argument('tenant');
        $tenant = ctype_digit($busca)
            ? Tenant::find($busca)
            : Tenant::whereRaw('lower(nome) = ?', [mb_strtolower($busca)])->first();

        if (! $tenant) {
            $this->error("Tenant '{$busca}' não encontrado.");

            return self::FAILURE;
        }

        $minutos = (int) $this->option('minutes');
        $url = URL::temporarySignedRoute('instagram.connect', now()->addMinutes($minutos), ['tenant' => $tenant->id]);

        $this->info("Link para conectar o Instagram de {$tenant->nome} (válido por {$minutos} min):");
        $this->line($url);

        return self::SUCCESS;
    }
}
