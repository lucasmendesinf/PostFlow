<?php

namespace Tests\Feature;

use App\Contracts\GeraConteudo;
use App\Contracts\Renderiza;
use App\Enums\AccountStatus;
use App\Enums\PostFormat;
use App\Enums\PostStatus;
use App\Models\Idea;
use App\Models\InstagramAccount;
use App\Models\Post;
use App\Models\Schedule;
use App\Models\Tenant;
use App\Models\UsageLog;
use App\Services\Conteudo\ClaudeGerador;
use App\Services\Conteudo\Conteudo;
use App\Services\Instagram\InstagramPublisher;
use App\Services\Pipeline\Ciclo;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class CicloTest extends TestCase
{
    use RefreshDatabase;

    private GeradorFalso $gerador;

    private RenderFalso $render;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        CarbonImmutable::setTestNow('2026-11-03 12:00:00');
        $this->travelTo(CarbonImmutable::now());

        $this->gerador = new GeradorFalso;
        $this->render = new RenderFalso;
        $this->app->instance(GeraConteudo::class, $this->gerador);
        $this->app->instance(Renderiza::class, $this->render);
        $this->app->bind(InstagramPublisher::class, fn () => new InstagramPublisher(config('services.instagram'), fn () => null));
    }

    private function conta(): InstagramAccount
    {
        $t = Tenant::factory()->create(['nome' => 'Calutec', 'timezone' => 'America/Sao_Paulo']);

        return InstagramAccount::factory()->create(['tenant_id' => $t->id, 'ig_user_id' => '1784', 'username' => 'calutec', 'access_token' => 'IGAA-tok', 'status' => AccountStatus::Ativo]);
    }

    private function ciclo(): Ciclo
    {
        return app(Ciclo::class);
    }

    public function test_agenda_cria_o_post_do_proximo_horario_e_gera_para_aprovacao(): void
    {
        $c = $this->conta();
        // terca 03/11/2026 12:00 UTC = 09:00 em Sao Paulo; agenda as 19:00 de terca (dia 2)
        $a = Schedule::create(['tenant_id' => $c->tenant_id, 'account_id' => $c->id, 'dias_semana' => [2], 'horario' => '19:00', 'formato' => PostFormat::Feed, 'ativo' => true]);

        $r = $this->ciclo()->rodar();

        $this->assertSame(1, $r['planejados']);
        $p = Post::withoutGlobalScopes()->sole();
        $this->assertSame($a->id, $p->schedule_id);
        $this->assertSame('2026-11-03 22:00:00', $p->agendado_para->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(PostStatus::AguardandoAprovacao, $p->status);
        $v = $p->versions()->sole();
        $this->assertStringContainsString('#calutec', $v->legenda);
        $this->assertCount(1, $v->media_urls);
        Storage::disk('public')->assertExists(str_replace('/storage/', '', parse_url($v->media_urls[0], PHP_URL_PATH)));
        $this->assertSame(1, UsageLog::withoutGlobalScopes()->count());

        // Rodar de novo nao duplica
        $this->ciclo()->rodar();
        $this->assertSame(1, Post::withoutGlobalScopes()->count());
    }

    public function test_texto_fora_do_quadro_volta_ao_claude_para_corrigir(): void
    {
        $c = $this->conta();
        Schedule::create(['tenant_id' => $c->tenant_id, 'account_id' => $c->id, 'dias_semana' => [2], 'horario' => '19:00', 'formato' => PostFormat::Feed, 'ativo' => true]);
        $this->render->problemasNaPrimeira = ['texto "Promoção" sai do quadro'];

        $this->ciclo()->rodar();

        $this->assertCount(2, $this->gerador->chamadas);
        $this->assertSame(['texto "Promoção" sai do quadro'], $this->gerador->chamadas[1]['problemas']);
        $this->assertSame(2, UsageLog::withoutGlobalScopes()->count());
    }

    public function test_agenda_sem_revisao_publica_sozinha_no_horario(): void
    {
        $c = $this->conta();
        Schedule::create(['tenant_id' => $c->tenant_id, 'account_id' => $c->id, 'dias_semana' => [2], 'horario' => '19:00', 'formato' => PostFormat::Feed, 'publicar_sem_revisao' => true, 'ativo' => true]);
        $this->ciclo()->rodar();
        $p = Post::withoutGlobalScopes()->sole();
        $this->assertSame(PostStatus::Aprovado, $p->status);

        Http::fake([
            'graph.instagram.com/v23.0/1784/content_publishing_limit*' => Http::response(['data' => [['quota_usage' => 3, 'config' => ['quota_total' => 100]]]]),
            'graph.instagram.com/v23.0/1784/media_publish' => Http::response(['id' => 'MEDIA-1']),
            'graph.instagram.com/v23.0/1784/media' => Http::response(['id' => 'CONT-1']),
            'graph.instagram.com/v23.0/CONT-1*' => Http::response(['status_code' => 'FINISHED']),
            'graph.instagram.com/v23.0/MEDIA-1*' => Http::response(['permalink' => 'https://www.instagram.com/p/abc/']),
        ]);
        // Antes do horario nao publica
        $this->assertSame(0, $this->ciclo()->publicar(CarbonImmutable::now()));
        $r = $this->ciclo()->publicar(CarbonImmutable::parse('2026-11-03 22:00:30'));

        $this->assertSame(1, $r);
        $p->refresh();
        $this->assertSame(PostStatus::Publicado, $p->status);
        $this->assertSame('MEDIA-1', $p->ig_media_id);
        Http::assertSent(fn (Request $q) => str_ends_with($q->url(), '/1784/media') && $q['caption'] === $p->versaoAprovada->legenda && str_ends_with($q['image_url'], '.jpg'));
        $this->assertSame('https://www.instagram.com/p/abc/', $p->events()->reorder('id', 'desc')->first()->detalhe);
    }

    public function test_ideia_vira_posts_por_formato_e_substitui_o_automatico_do_mesmo_horario(): void
    {
        $c = $this->conta();
        Schedule::create(['tenant_id' => $c->tenant_id, 'account_id' => $c->id, 'dias_semana' => [2], 'horario' => '19:00', 'formato' => PostFormat::Feed, 'ativo' => true]);
        $this->ciclo()->planejar(CarbonImmutable::now());
        $auto = Post::withoutGlobalScopes()->sole();

        Idea::create(['tenant_id' => $c->tenant_id, 'account_id' => $c->id, 'modo' => 'livre', 'data_publicacao' => '2026-11-03', 'horario' => '19:00', 'formatos' => ['feed', 'stories'], 'descricao' => 'Promoção de site em novembro', 'status' => 'pendente']);
        $this->ciclo()->planejar(CarbonImmutable::now());

        $this->assertSame(PostStatus::Rejeitado, $auto->refresh()->status);
        $daIdeia = Post::withoutGlobalScopes()->whereNotNull('idea_id')->orderBy('formato')->get();
        $this->assertSame(['feed', 'stories'], $daIdeia->map(fn ($p) => $p->formato->value)->all());
        $this->assertSame('gerado', Idea::withoutGlobalScopes()->sole()->status);
    }

    public function test_sem_aprovacao_ate_uma_hora_antes_expira(): void
    {
        $c = $this->conta();
        $p = Post::factory()->create(['tenant_id' => $c->tenant_id, 'account_id' => $c->id, 'status' => PostStatus::AguardandoAprovacao, 'agendado_para' => CarbonImmutable::now()->addMinutes(50)]);

        $this->assertSame(1, $this->ciclo()->expirar(CarbonImmutable::now()));
        $this->assertSame(PostStatus::Expirado, $p->refresh()->status);
    }

    public function test_nova_versao_pedida_pelo_painel_leva_o_comentario_e_mantem_o_historico(): void
    {
        $c = $this->conta();
        $p = Post::factory()->create(['tenant_id' => $c->tenant_id, 'account_id' => $c->id, 'agendado_para' => CarbonImmutable::now()->addDay()]);
        $this->ciclo()->gerar(CarbonImmutable::now());
        // O painel pede nova versao: volta para "agendado" com o comentario
        $p->refresh()->forceFill(['status' => PostStatus::Agendado, 'comentario_pendente' => 'mais destaque no preço'])->save();

        $this->ciclo()->gerar(CarbonImmutable::now());

        $p->refresh();
        $this->assertSame([1, 2], $p->versions()->pluck('versao')->all());
        $this->assertSame('mais destaque no preço', $this->gerador->chamadas[1]['comentario']);
        $this->assertNotNull($this->gerador->chamadas[1]['anterior']);
        $this->assertNull($p->comentario_pendente);
        $this->assertSame(PostStatus::AguardandoAprovacao, $p->status);
    }

    public function test_falha_tenta_de_novo_com_espera_e_depois_vai_para_erro(): void
    {
        $c = $this->conta();
        $p = Post::factory()->create(['tenant_id' => $c->tenant_id, 'account_id' => $c->id, 'agendado_para' => CarbonImmutable::now()->addDay()]);
        $this->gerador->falhar = true;
        $agora = CarbonImmutable::now();

        $this->ciclo()->gerar($agora);
        $p->refresh();
        $this->assertSame(PostStatus::Agendado, $p->status);
        $this->assertSame(1, $p->tentativas);
        $this->assertSame($agora->addMinute()->format('H:i'), $p->proxima_tentativa_em->format('H:i'));

        // Antes da espera, nao tenta
        $this->ciclo()->gerar($agora->addSeconds(30));
        $this->assertSame(1, $p->refresh()->tentativas);

        foreach ([2, 10, 30] as $min) {
            $this->ciclo()->gerar($agora->addMinutes($min));
        }
        $p->refresh();
        $this->assertSame(PostStatus::Erro, $p->status);
        $this->assertStringContainsString('falhou 4 vezes', $p->erro);
    }

    public function test_token_invalido_marca_a_conta_para_reconectar(): void
    {
        $c = $this->conta();
        $p = Post::factory()->create(['tenant_id' => $c->tenant_id, 'account_id' => $c->id, 'agendado_para' => CarbonImmutable::now()->addMinutes(5)]);
        $this->ciclo()->gerarUm($p);
        $p->refresh()->forceFill(['status' => PostStatus::Aprovado])->save();
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid OAuth access token', 'type' => 'OAuthException', 'code' => 190]], 400)]);

        $this->ciclo()->publicar(CarbonImmutable::now()->addMinutes(6));

        $this->assertSame(PostStatus::Erro, $p->refresh()->status);
        $this->assertSame(AccountStatus::Reconectar, $c->refresh()->status);
    }

    public function test_carrossel_cria_um_container_por_slide_e_o_carousel(): void
    {
        $c = $this->conta();
        Http::fake([
            'graph.instagram.com/v23.0/1784/content_publishing_limit*' => Http::response(['data' => [['quota_usage' => 0, 'config' => ['quota_total' => 100]]]]),
            'graph.instagram.com/v23.0/1784/media_publish' => Http::response(['id' => 'M']),
            'graph.instagram.com/v23.0/1784/media' => Http::sequence()->push(['id' => 'F1'])->push(['id' => 'F2'])->push(['id' => 'CAR']),
            'graph.instagram.com/*' => Http::response(['status_code' => 'FINISHED']),
        ]);
        $pub = new InstagramPublisher(config('services.instagram'), fn () => null);

        $r = $pub->publicar($c, PostFormat::Carrossel, ['https://x/1.jpg', 'https://x/2.jpg'], 'Legenda');

        $this->assertSame(['container_id' => 'CAR', 'media_id' => 'M'], $r);
        Http::assertSent(fn (Request $q) => str_ends_with($q->url(), '/1784/media') && ($q['media_type'] ?? null) === 'CAROUSEL' && $q['children'] === 'F1,F2');
    }

    public function test_validacao_da_resposta_do_claude(): void
    {
        $ok = ClaudeGerador::validar(['tema' => 'Site', 'legenda' => 'Texto', 'hashtags' => ['sites', '#Sites', 'calutec'], 'alt_text' => '', 'slides' => [['html' => '<div></div>', 'css' => '']]], PostFormat::Feed);
        $this->assertSame(['#sites', '#calutec'], $ok->hashtags);
        $this->assertSame("Texto\n\n#sites #calutec", $ok->legendaCompleta());

        $this->expectException(RuntimeException::class);
        ClaudeGerador::validar(['tema' => 'x', 'legenda' => 'y', 'hashtags' => [], 'alt_text' => '', 'slides' => [['html' => '<div></div>', 'css' => '']]], PostFormat::Carrossel);
    }
}

class GeradorFalso implements GeraConteudo
{
    public array $chamadas = [];

    public bool $falhar = false;

    public function gerar(Post $post, array $assets, ?string $comentario = null, array $problemas = [], ?Conteudo $anterior = null): Conteudo
    {
        $this->chamadas[] = compact('assets', 'comentario', 'problemas', 'anterior');
        if ($this->falhar) {
            throw new RuntimeException('API fora do ar');
        }
        $n = $post->formato === PostFormat::Carrossel ? 3 : 1;

        return new Conteudo('Site institucional', 'Seu site pronto em 15 dias.', ['#calutec', '#sites'], 'Arte laranja', array_fill(0, $n, ['html' => '<div>Oi</div>', 'css' => '']), ['modelo' => 'claude-haiku-5-5', 'entrada' => 3000, 'saida' => 900]);
    }
}

class RenderFalso implements Renderiza
{
    public array $problemasNaPrimeira = [];

    private int $vez = 0;

    public function renderizar(PostFormat $formato, array $slides, array $assets = []): array
    {
        $this->vez++;

        return array_map(fn () => ['jpeg' => "\xFF\xD8jpeg-{$this->vez}", 'problemas' => $this->vez === 1 ? $this->problemasNaPrimeira : []], $slides);
    }
}
