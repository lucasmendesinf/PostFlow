<?php

namespace App\Jobs\Pipeline;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Job de teste da fundação: percorre as filas content -> render -> publish,
 * na mesma ordem do fluxo real, e registra cada passagem no cache.
 * Na etapa render também confere se o renderizador (Node) e o
 * pré-processador (Python) respondem, quando configurados.
 */
class PipelineProbeJob implements ShouldQueue
{
    use Queueable;

    public const ETAPAS = ['content', 'render', 'publish'];

    public int $tries = 3;

    /** Espera crescente entre tentativas (segundos). */
    public array $backoff = [5, 30, 120];

    public function __construct(public string $runId, public string $etapa)
    {
        $this->onQueue(config("postflow.queues.{$etapa}"));
    }

    /** Despacha a cadeia completa e devolve o id da execução. */
    public static function dispatchChain(): string
    {
        $runId = (string) Str::uuid();

        Bus::chain(array_map(fn (string $etapa) => new self($runId, $etapa), self::ETAPAS))->dispatch();

        return $runId;
    }

    public function handle(): void
    {
        $registro = [
            'etapa' => $this->etapa,
            'fila' => $this->queue,
            'processado_em' => now()->toIso8601String(),
            'pid' => getmypid(),
        ];

        if ($this->etapa === 'render') {
            $registro['servicos'] = [
                'renderer' => $this->checarServico(config('postflow.services.renderer_url')),
                'preprocessor' => $this->checarServico(config('postflow.services.preprocessor_url')),
            ];
        }

        $passagens = Cache::get(self::cacheKey($this->runId), []);
        $passagens[] = $registro;
        Cache::put(self::cacheKey($this->runId), $passagens, now()->addHour());
    }

    public static function cacheKey(string $runId): string
    {
        return "pipeline-probe:{$runId}";
    }

    /** @return array<int, array<string, mixed>> */
    public static function passagens(string $runId): array
    {
        return Cache::get(self::cacheKey($runId), []);
    }

    private function checarServico(?string $url): string
    {
        if (blank($url)) {
            return 'nao_configurado';
        }

        try {
            return Http::timeout(5)->get(rtrim($url, '/').'/health')->successful() ? 'ok' : 'falhou';
        } catch (Throwable) {
            return 'inacessivel';
        }
    }
}
