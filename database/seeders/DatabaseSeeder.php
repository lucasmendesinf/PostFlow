<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Clientes da fase 1 (uso interno). Idempotente: pode rodar de novo.
     */
    public function run(): void
    {
        foreach (['Calutec', 'JA Conveniência'] as $nome) {
            Tenant::firstOrCreate(['nome' => $nome], ['plano' => 'interno', 'status' => 'ativo', 'timezone' => 'America/Sao_Paulo']);
        }
    }
}
