<?php

namespace App\Console\Commands;

use App\Jobs\Pipeline\PipelineProbeJob;
use Illuminate\Console\Command;

class PipelineTestCommand extends Command
{
    protected $signature = 'postflow:pipeline-test {--timeout=60 : Segundos para esperar os workers}';

    protected $description = 'Despacha um job de teste pelas filas content, render e publish e espera os workers processarem';

    public function handle(): int
    {
        $runId = PipelineProbeJob::dispatchChain();
        $this->info("Job de teste despachado (execução {$runId}) na conexão '".config('queue.default')."'.");

        $limite = now()->addSeconds((int) $this->option('timeout'));
        $total = count(PipelineProbeJob::ETAPAS);

        do {
            $passagens = PipelineProbeJob::passagens($runId);
            if (count($passagens) >= $total) {
                break;
            }
            usleep(500_000);
        } while (now()->lt($limite));

        $this->table(
            ['Etapa', 'Fila', 'Processado em', 'PID', 'Serviços'],
            array_map(fn (array $p) => [
                $p['etapa'],
                $p['fila'],
                $p['processado_em'],
                $p['pid'],
                isset($p['servicos']) ? collect($p['servicos'])->map(fn ($s, $n) => "{$n}: {$s}")->implode(', ') : '-',
            ], $passagens),
        );

        if (count($passagens) < $total) {
            $this->error('Tempo esgotado: '.count($passagens)."/{$total} etapas processadas. Os workers das filas estão rodando?");

            return self::FAILURE;
        }

        $this->info('OK: o job passou pelas três filas.');

        return self::SUCCESS;
    }
}
