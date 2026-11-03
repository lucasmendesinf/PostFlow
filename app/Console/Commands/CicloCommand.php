<?php

namespace App\Console\Commands;

use App\Services\Pipeline\Ciclo;
use Illuminate\Console\Command;

class CicloCommand extends Command
{
    protected $signature = 'postflow:ciclo';

    protected $description = 'Uma rodada do PostFlow: cria os posts das ideias e agendas, gera, expira e publica';

    public function handle(Ciclo $ciclo): int
    {
        $r = $ciclo->rodar();
        $this->line(sprintf('planejados %d · gerados %d · expirados %d · publicados %d', $r['planejados'], $r['gerados'], $r['expirados'], $r['publicados']));

        return self::SUCCESS;
    }
}
