<?php

namespace App\Services\Pipeline;

use App\Contracts\GeraConteudo;
use App\Contracts\Renderiza;
use App\Enums\AccountStatus;
use App\Enums\PostFormat;
use App\Enums\PostStatus;
use App\Models\CatalogItem;
use App\Models\Idea;
use App\Models\Post;
use App\Models\PostVersion;
use App\Models\Schedule;
use App\Models\UsageLog;
use App\Services\Conteudo\Conteudo;
use App\Services\Instagram\InstagramAccountService;
use App\Services\Instagram\InstagramApiException;
use App\Services\Instagram\InstagramPublisher;
use App\Services\Instagram\LimitePublicacaoAtingido;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Uma rodada do PostFlow (php artisan postflow:ciclo, a cada minuto pelo cron):
 *  1. planejar: ideias pendentes viram posts; agendas criam o post do proximo horario (com antecedencia);
 *  2. gerar:    Claude escreve o roteiro, o renderizador faz a arte, o post vai para aprovacao
 *               (ou direto para "aprovado" se a agenda publica sem revisao);
 *  3. expirar:  sem aprovacao ate 1 h antes do horario, nao publica;
 *  4. publicar: aprovados no horario vao para o Instagram.
 * O painel (admin) aprova, rejeita, edita a legenda e pede nova versao gravando direto no banco.
 */
class Ciclo
{
    private array $cfg;

    public function __construct(
        private readonly GeraConteudo $gerador,
        private readonly Renderiza $renderizador,
        private readonly InstagramPublisher $publisher,
        private readonly InstagramAccountService $contas,
    ) {
        $this->cfg = config('postflow.ciclo');
    }

    /** @return array<string, int> quantos posts cada etapa mexeu */
    public function rodar(?CarbonImmutable $agora = null): array
    {
        $agora ??= CarbonImmutable::now();

        return [
            'planejados' => $this->planejar($agora),
            'gerados' => $this->gerar($agora),
            'expirados' => $this->expirar($agora),
            'publicados' => $this->publicar($agora),
        ];
    }

    // ------------------------------------------------------------ 1. planejar
    public function planejar(CarbonImmutable $agora): int
    {
        $n = 0;

        // Ideias: viram posts na hora (assim da tempo de ver e aprovar com calma)
        $ideias = Idea::query()->where('status', 'pendente')
            ->whereHas('account', fn ($q) => $q->where('status', AccountStatus::Ativo))
            ->whereHas('tenant', fn ($q) => $q->where('status', 'ativo'))->with('tenant')->get();
        foreach ($ideias as $ideia) {
            $quando = CarbonImmutable::parse($ideia->data_publicacao->format('Y-m-d').' '.substr($ideia->horario, 0, 5), $ideia->tenant->timezone ?: 'America/Sao_Paulo')->utc();
            DB::transaction(function () use ($ideia, $quando, &$n) {
                foreach ((array) $ideia->formatos as $f) {
                    $formato = PostFormat::tryFrom($f);
                    if (! $formato) continue;
                    // No mesmo horario, a ideia substitui o post automatico da agenda
                    Post::query()->where('account_id', $ideia->account_id)->where('agendado_para', $quando)->where('formato', $formato)
                        ->whereNotNull('schedule_id')->whereIn('status', [PostStatus::Agendado, PostStatus::Gerando, PostStatus::AguardandoAprovacao, PostStatus::Aprovado])
                        ->get()->each(fn (Post $p) => $p->transitionTo(PostStatus::Rejeitado, "substituído pela ideia #{$ideia->id}"));
                    $p = Post::create(['tenant_id' => $ideia->tenant_id, 'account_id' => $ideia->account_id, 'idea_id' => $ideia->id, 'formato' => $formato, 'agendado_para' => $quando]);
                    $p->events()->create(['status_anterior' => null, 'status_novo' => PostStatus::Agendado->value, 'detalhe' => "criado pela ideia #{$ideia->id}"]);
                    $n++;
                }
                $ideia->forceFill(['status' => 'gerado'])->save();
            });
        }

        // Agendas: o proximo horario dentro da antecedencia
        $limite = $agora->addHours($this->cfg['antecedencia_horas']);
        $agendas = Schedule::query()->where('ativo', true)
            ->whereHas('account', fn ($q) => $q->where('status', AccountStatus::Ativo))
            ->whereHas('tenant', fn ($q) => $q->where('status', 'ativo'))->with('tenant')->get();
        foreach ($agendas as $a) {
            $tz = $a->tenant->timezone ?: 'America/Sao_Paulo';
            $dias = array_map('intval', (array) $a->dias_semana);
            for ($d = 0; $d <= 2; $d++) {
                $quando = CarbonImmutable::parse($agora->setTimezone($tz)->addDays($d)->format('Y-m-d').' '.substr($a->horario, 0, 5), $tz)->utc();
                if (! in_array($quando->setTimezone($tz)->isoWeekday(), $dias, true) || $quando <= $agora || $quando > $limite) continue;
                $existe = Post::query()->where('account_id', $a->account_id)->where('agendado_para', $quando)
                    ->where(fn ($q) => $q->where('schedule_id', $a->id)->orWhereNotNull('idea_id'))->exists();
                if ($existe) continue;
                $p = Post::create(['tenant_id' => $a->tenant_id, 'account_id' => $a->account_id, 'schedule_id' => $a->id, 'formato' => $a->formato, 'agendado_para' => $quando]);
                $p->events()->create(['status_anterior' => null, 'status_novo' => PostStatus::Agendado->value, 'detalhe' => "criado pela agenda #{$a->id}"]);
                $n++;
            }
        }

        return $n;
    }

    // ------------------------------------------------------------ 2. gerar
    public function gerar(CarbonImmutable $agora): int
    {
        $semOrcamento = $this->orcamentoEsgotado($agora);

        // Nao deu tempo de gerar: o horario ja passou
        Post::query()->where('status', PostStatus::Agendado)->where('agendado_para', '<=', $agora)->get()
            ->each(fn (Post $p) => $p->transitionTo(PostStatus::Expirado, $semOrcamento
                ? 'orçamento do mês do Claude atingido: o post não foi gerado'
                : 'o horário passou antes de o post ficar pronto'));

        // Orcamento do mes atingido (e "pausar" ligado no painel): nao chama o Claude ate o mes virar ou o limite subir
        if ($semOrcamento) {
            return 0;
        }

        $posts = Post::query()->where('status', PostStatus::Agendado)
            ->where(fn ($q) => $q->whereNull('proxima_tentativa_em')->orWhere('proxima_tentativa_em', '<=', $agora))
            ->orderBy('agendado_para')->limit($this->cfg['gerar_por_rodada'])->get();

        $n = 0;
        foreach ($posts as $post) {
            $post->transitionTo(PostStatus::Gerando, $post->comentario_pendente ? "nova versão pedida: {$post->comentario_pendente}" : null);
            try {
                $this->gerarUm($post);
                $n++;
            } catch (Throwable $e) {
                Log::warning('PostFlow: falha ao gerar post', ['post_id' => $post->id, 'erro' => $e->getMessage()]);
                $this->falhou($post, PostStatus::Agendado, $agora, 'geração', $e);
            }
        }

        return $n;
    }

    public function gerarUm(Post $post): PostVersion
    {
        $post->loadMissing(['tenant.brandProfile', 'idea', 'schedule']);
        [$assets, $descricoes] = $this->assets($post);
        $ultima = $post->versions()->reorder('versao', 'desc')->first();
        $anterior = $ultima?->saida_claude ? Conteudo::deArray($ultima->saida_claude) : null;
        $comentario = $post->comentario_pendente;

        $uso = [];
        $conteudo = $this->gerador->gerar($post, $descricoes, $comentario, [], $anterior);
        $uso[] = $conteudo->uso;
        $imagens = $this->renderizador->renderizar($post->formato, $conteudo->slides, $assets);

        // Texto saindo do quadro: devolve ao Claude para corrigir (uma vez)
        $problemas = array_values(array_unique(array_merge(...array_map(fn ($i) => $i['problemas'], $imagens))));
        if ($problemas) {
            $conteudo = $this->gerador->gerar($post, $descricoes, $comentario, $problemas, $conteudo);
            $uso[] = $conteudo->uso;
            $imagens = $this->renderizador->renderizar($post->formato, $conteudo->slides, $assets);
        }

        $versao = (int) $post->versions()->max('versao') + 1;
        $disco = Storage::disk($this->cfg['disco']);
        $urls = [];
        foreach ($imagens as $i => $img) {
            $caminho = "posts/{$post->tenant_id}/{$post->id}/v{$versao}-".($i + 1).'-'.substr(sha1($img['jpeg']), 0, 8).'.jpg';
            $disco->put($caminho, $img['jpeg'], 'public');
            $urls[] = $disco->url($caminho);
        }

        return DB::transaction(function () use ($post, $conteudo, $urls, $versao, $comentario, $uso) {
            $v = $post->versions()->create([
                'versao' => $versao,
                'saida_claude' => $conteudo->paraArray(),
                'legenda' => $conteudo->legendaCompleta(),
                'media_urls' => $urls,
                'comentario' => $comentario,
            ]);
            foreach ($uso as $u) {
                $this->registrarUso($post, $u);
            }
            $post->forceFill(['comentario_pendente' => null, 'tentativas' => 0, 'proxima_tentativa_em' => null, 'erro' => null, 'versao_aprovada_id' => null])->save();

            $semRevisao = $post->schedule_id && ! $post->idea_id && $post->schedule?->publicar_sem_revisao;
            if ($semRevisao) {
                $post->forceFill(['versao_aprovada_id' => $v->id])->save();
                $post->transitionTo(PostStatus::Aprovado, "versão {$versao} pronta; agenda publica sem revisão");
            } else {
                $post->transitionTo(PostStatus::AguardandoAprovacao, "versão {$versao} pronta para aprovar");
            }

            return $v;
        });
    }

    /**
     * Orcamento mensal do Claude, definido no painel (tabela configuracoes, chave "orcamento_claude"):
     * { usd_mes: 5, pausar: true }. Sem orcamento ou com "pausar" desligado, nunca bloqueia.
     */
    public function orcamentoEsgotado(CarbonImmutable $agora): bool
    {
        $cfg = DB::table('configuracoes')->where('chave', 'orcamento_claude')->value('valor');
        $cfg = $cfg ? json_decode($cfg, true) : null;
        if (! $cfg || empty($cfg['pausar']) || ! isset($cfg['usd_mes']) || (float) $cfg['usd_mes'] <= 0) {
            return false;
        }
        $gasto = (float) UsageLog::query()->withoutGlobalScopes()
            ->where('criado_em', '>=', $agora->startOfMonth())->sum('custo_usd');

        return $gasto >= (float) $cfg['usd_mes'];
    }

    // ------------------------------------------------------------ 3. expirar
    public function expirar(CarbonImmutable $agora): int
    {
        $limite = $agora->addMinutes($this->cfg['expira_minutos_antes']);
        $posts = Post::query()->where('status', PostStatus::AguardandoAprovacao)->where('agendado_para', '<=', $limite)->get();
        foreach ($posts as $p) {
            $p->transitionTo(PostStatus::Expirado, 'não foi aprovado a tempo; não publicado');
        }

        return $posts->count();
    }

    // ------------------------------------------------------------ 4. publicar
    public function publicar(CarbonImmutable $agora): int
    {
        $posts = Post::query()->where('status', PostStatus::Aprovado)->where('agendado_para', '<=', $agora)
            ->where(fn ($q) => $q->whereNull('proxima_tentativa_em')->orWhere('proxima_tentativa_em', '<=', $agora))
            ->with('account')->orderBy('agendado_para')->limit(5)->get();

        $n = 0;
        foreach ($posts as $post) {
            $v = $post->versaoAprovada ?? $post->versions()->reorder('versao', 'desc')->first();
            if (! $v) {
                $post->transitionTo(PostStatus::Erro, 'aprovado sem nenhuma versão gerada');
                continue;
            }
            if ($post->account->status !== AccountStatus::Ativo || blank($post->account->access_token)) {
                $post->transitionTo(PostStatus::Erro, "a conta @{$post->account->username} precisa ser reconectada");
                continue;
            }
            $post->transitionTo(PostStatus::Publicando, "publicando a versão {$v->versao}");
            try {
                $r = $this->publisher->publicar($post->account, $post->formato, (array) $v->media_urls, (string) $v->legenda);
                $link = $this->publisher->permalink($post->account, $r['media_id']);
                $post->forceFill(['ig_container_id' => $r['container_id'], 'ig_media_id' => $r['media_id'], 'publicado_em' => now(), 'tentativas' => 0, 'proxima_tentativa_em' => null, 'erro' => null, 'versao_aprovada_id' => $v->id])->save();
                $post->transitionTo(PostStatus::Publicado, $link ?: "media {$r['media_id']}");
                $n++;
            } catch (LimitePublicacaoAtingido $e) {
                $post->forceFill(['proxima_tentativa_em' => $agora->addHour()])->save();
                $post->transitionTo(PostStatus::Aprovado, $e->getMessage().' Nova tentativa em 1 h.');
            } catch (InstagramApiException $e) {
                if ($e->isTokenInvalid()) {
                    $this->contas->markReconnect($post->account, $e->getMessage());
                    $post->transitionTo(PostStatus::Erro, 'token do Instagram inválido: reconecte a conta');
                    continue;
                }
                $this->falhou($post, PostStatus::Aprovado, $agora, 'publicação', $e);
            } catch (Throwable $e) {
                $this->falhou($post, PostStatus::Aprovado, $agora, 'publicação', $e);
            }
        }

        return $n;
    }

    // ------------------------------------------------------------ apoio
    private function falhou(Post $post, PostStatus $volta, CarbonImmutable $agora, string $etapa, Throwable $e): void
    {
        $esperas = $this->cfg['esperas'];
        $t = $post->tentativas + 1;
        $msg = self::mensagemCurta($e);
        if ($t >= count($esperas) + 1) {
            $post->forceFill(['tentativas' => $t])->save();
            $post->transitionTo(PostStatus::Erro, "{$etapa} falhou {$t} vezes: {$msg}");

            return;
        }
        $post->forceFill(['tentativas' => $t, 'proxima_tentativa_em' => $agora->addMinutes($esperas[$t - 1])])->save();
        $post->transitionTo($volta, "{$etapa} falhou (tentativa {$t}), nova tentativa em {$esperas[$t - 1]} min: {$msg}");
    }

    // Erros do SDK vem com o JSON inteiro da resposta; guarda so o essencial
    public static function mensagemCurta(Throwable $e): string
    {
        $m = $e->getMessage();
        if (preg_match('/"message":\s*"([^"]+)"/', $m, $x)) {
            $m = strtok($m, "\n").': '.$x[1];
        }

        return mb_substr(trim($m), 0, 300);
    }

    private function registrarUso(Post $post, array $u): void
    {
        if (! $u) return;
        $preco = config('postflow.claude.precos.'.($u['modelo'] ?? ''), ['entrada' => 0, 'saida' => 0]);
        // cache: leitura a 10% e escrita a 125% do preco de entrada (aproximacao)
        $custo = (($u['entrada'] ?? 0) + 0.1 * ($u['cache_leitura'] ?? 0) + 1.25 * ($u['cache_escrita'] ?? 0)) * $preco['entrada'] / 1e6
            + ($u['saida'] ?? 0) * $preco['saida'] / 1e6;
        UsageLog::create([
            'tenant_id' => $post->tenant_id, 'post_id' => $post->id, 'modelo' => $u['modelo'] ?? null,
            'tokens_entrada' => ($u['entrada'] ?? 0) + ($u['cache_leitura'] ?? 0) + ($u['cache_escrita'] ?? 0),
            'tokens_saida' => $u['saida'] ?? 0, 'custo_usd' => round($custo, 6),
        ]);
    }

    /**
     * Imagens que a arte pode usar: o logo da marca e as fotos do catalogo.
     *
     * @return array{0: array<string, string>, 1: array<string, string>} [chave => data URI], [chave => descricao]
     */
    private function assets(Post $post): array
    {
        $dados = [];
        $desc = [];
        $marca = $post->tenant->brandProfile;
        if ($marca?->logo_url && ($uri = $this->dataUri($marca->logo_url))) {
            $dados['logo'] = $uri;
            $desc['logo'] = "logo da {$post->tenant->nome}";
        }
        $itens = CatalogItem::query()->where('tenant_id', $post->tenant_id)->where('ativo', true)->whereNotNull('imagem_url')->limit(6)->get();
        foreach ($itens as $c) {
            if ($uri = $this->dataUri($c->imagem_url)) {
                $dados["item{$c->id}"] = $uri;
                $desc["item{$c->id}"] = "foto de {$c->nome}";
            }
        }

        return [$dados, $desc];
    }

    // Baixa a imagem uma vez (cache em storage) e devolve como data URI; null se nao der
    private function dataUri(string $url): ?string
    {
        try {
            $cache = 'cache-assets/'.sha1($url);
            $disco = Storage::disk('local');
            if (! $disco->exists($cache)) {
                $r = Http::timeout(20)->get($url);
                $tipo = strtolower(strtok((string) $r->header('Content-Type'), ';'));
                if ($r->failed() || ! in_array($tipo, ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'], true) || strlen($r->body()) > 5 * 1024 * 1024) {
                    return null;
                }
                $disco->put($cache, $tipo."\n".base64_encode($r->body()));
            }
            [$tipo, $b64] = explode("\n", $disco->get($cache), 2);

            return "data:{$tipo};base64,{$b64}";
        } catch (Throwable) {
            return null;
        }
    }
}
