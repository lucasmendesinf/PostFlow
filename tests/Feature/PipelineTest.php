<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Jobs\Pipeline\PipelineProbeJob;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_cadeia_e_despachada_na_ordem_content_render_publish(): void
    {
        // Queue::fake (e não Bus::fake) para passar pelo PendingChain real, que respeita a fila de cada job.
        Queue::fake();

        PipelineProbeJob::dispatchChain();

        Queue::assertPushedOn('content', PipelineProbeJob::class, function (PipelineProbeJob $primeiro) {
            $encadeados = array_map('unserialize', $primeiro->chained);

            return $primeiro->etapa === 'content'
                && array_map(fn ($job) => [$job->etapa, $job->queue], $encadeados) === [['render', 'render'], ['publish', 'publish']];
        });
    }

    public function test_job_passa_pelas_tres_filas(): void
    {
        $runId = PipelineProbeJob::dispatchChain();

        $this->assertSame(
            ['content', 'render', 'publish'],
            array_column(PipelineProbeJob::passagens($runId), 'fila'),
        );
    }

    public function test_etapa_render_confere_os_servicos_node_e_python(): void
    {
        config([
            'postflow.services.renderer_url' => 'http://renderer:3001',
            'postflow.services.preprocessor_url' => 'http://preprocessor:3002',
        ]);
        Http::fake([
            'renderer:3001/health' => Http::response(['status' => 'ok']),
            'preprocessor:3002/health' => Http::response([], 500),
        ]);

        $runId = PipelineProbeJob::dispatchChain();
        $render = collect(PipelineProbeJob::passagens($runId))->firstWhere('etapa', 'render');

        $this->assertSame(['renderer' => 'ok', 'preprocessor' => 'falhou'], $render['servicos']);
    }

    public function test_comando_de_teste_conclui_com_sucesso(): void
    {
        $this->artisan('postflow:pipeline-test', ['--timeout' => 5])
            ->expectsOutputToContain('OK: o job passou pelas três filas.')
            ->assertSuccessful();
    }

    public function test_mudanca_de_status_do_post_gera_evento_de_auditoria(): void
    {
        $post = Post::factory()->create();

        $post->transitionTo(PostStatus::Gerando);
        $post->transitionTo(PostStatus::Erro, 'Timeout no Claude');

        $this->assertSame(PostStatus::Erro, $post->fresh()->status);
        $this->assertSame('Timeout no Claude', $post->fresh()->erro);
        $this->assertSame(
            [['agendado', 'gerando'], ['gerando', 'erro']],
            $post->events->map(fn ($e) => [$e->status_anterior, $e->status_novo])->all(),
        );
    }
}
