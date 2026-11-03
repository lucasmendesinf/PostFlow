<?php

namespace App\Services\Conteudo;

use Anthropic\Client;
use App\Contracts\GeraConteudo;
use App\Enums\PostFormat;
use App\Models\CatalogItem;
use App\Models\Post;
use App\Models\PostVersion;
use App\Models\StylePreset;
use RuntimeException;

/**
 * Gera o roteiro do post com a API do Claude (padrao: claude-haiku-5-5, o mais barato).
 * Saida estruturada (JSON schema) e validada aqui: formato, quantidade de slides, legenda e hashtags.
 */
class ClaudeGerador implements GeraConteudo
{
    public function __construct(private readonly array $config) {}

    public function gerar(Post $post, array $assets, ?string $comentario = null, array $problemas = [], ?Conteudo $anterior = null): Conteudo
    {
        if (blank($this->config['api_key'] ?? null)) {
            throw new RuntimeException('ANTHROPIC_API_KEY não configurada no .env do PostFlow.');
        }

        $post->loadMissing(['tenant.brandProfile', 'idea', 'schedule']);
        $marca = $post->tenant->brandProfile;
        $estilo = $this->estilo($post, $marca?->estilo_padrao_id);
        $catalogo = CatalogItem::query()->where('tenant_id', $post->tenant_id)->where('ativo', true)
            ->where(fn ($q) => $q->whereNull('fim')->orWhere('fim', '>=', now()))
            ->orderBy('tipo')->limit(20)->get();
        $recentes = $this->recentes($post);

        $pedido = Prompt::pedido($post, $marca, $estilo, $assets, $recentes, $catalogo, $comentario, $problemas, $anterior);
        $modelo = $this->config['modelo'] ?: 'claude-haiku-5-5';

        $client = new Client(apiKey: $this->config['api_key'], baseUrl: $this->config['base_url'] ?: null);
        $msg = $client->messages->create(
            model: $modelo,
            maxTokens: (int) ($this->config['max_tokens'] ?? 16000),
            system: [['type' => 'text', 'text' => Prompt::sistema(), 'cacheControl' => ['type' => 'ephemeral']]],
            messages: [['role' => 'user', 'content' => $pedido]],
            outputConfig: [
                'effort' => $this->config['esforco'] ?: 'medium',
                'format' => ['type' => 'json_schema', 'schema' => Prompt::schema()],
            ],
        );

        $uso = [
            'modelo' => $modelo,
            'entrada' => (int) ($msg->usage->inputTokens ?? 0),
            'saida' => (int) ($msg->usage->outputTokens ?? 0),
            'cache_leitura' => (int) ($msg->usage->cacheReadInputTokens ?? 0),
            'cache_escrita' => (int) ($msg->usage->cacheCreationInputTokens ?? 0),
        ];

        if ($msg->stopReason === 'refusal') {
            throw new RuntimeException('O Claude recusou o pedido'.($msg->stopDetails?->explanation ? ': '.$msg->stopDetails->explanation : '.'));
        }
        if ($msg->stopReason === 'max_tokens') {
            throw new RuntimeException('A resposta do Claude foi cortada (max_tokens). Aumente POSTFLOW_CLAUDE_MAX_TOKENS.');
        }

        $texto = '';
        foreach ($msg->content as $bloco) {
            if ($bloco->type === 'text') {
                $texto .= $bloco->text;
            }
        }

        return self::validar(json_decode($texto, true), $post->formato, $uso);
    }

    /** Confere a resposta e devolve o Conteudo; erro claro se algo nao bate. */
    public static function validar(mixed $d, PostFormat $formato, array $uso = []): Conteudo
    {
        if (! is_array($d)) {
            throw new RuntimeException('O Claude não devolveu um JSON válido.');
        }
        $slides = array_values(array_filter($d['slides'] ?? [], fn ($s) => is_array($s) && filled($s['html'] ?? null)));
        [$min, $max] = $formato === PostFormat::Carrossel ? [2, 10] : [1, 1];
        if (count($slides) < $min) {
            throw new RuntimeException("O Claude devolveu ".count($slides)." slide(s); o formato {$formato->value} precisa de pelo menos {$min}.");
        }
        $slides = array_map(fn ($s) => ['html' => (string) $s['html'], 'css' => (string) ($s['css'] ?? '')], array_slice($slides, 0, $max));

        $hashtags = [];
        foreach ((array) ($d['hashtags'] ?? []) as $h) {
            $h = '#'.ltrim(preg_replace('/\s+/', '', (string) $h), '#');
            if ($h !== '#' && ! in_array(mb_strtolower($h), array_map('mb_strtolower', $hashtags), true)) $hashtags[] = $h;
        }
        $hashtags = array_slice($hashtags, 0, 30);

        $legenda = trim((string) ($d['legenda'] ?? ''));
        if ($legenda === '') {
            throw new RuntimeException('O Claude devolveu a legenda vazia.');
        }
        $c = new Conteudo(mb_substr(trim((string) ($d['tema'] ?? '')), 0, 200), $legenda, $hashtags, mb_substr(trim((string) ($d['alt_text'] ?? '')), 0, 1000), $slides, $uso);
        if (mb_strlen($c->legendaCompleta()) > 2200) {
            $c = new Conteudo($c->tema, mb_substr($legenda, 0, 2200 - mb_strlen(implode(' ', $hashtags)) - 3), $hashtags, $c->altText, $slides, $uso);
        }

        return $c;
    }

    private function estilo(Post $post, ?int $padrao): ?StylePreset
    {
        $id = $post->idea?->estilo_id ?? $post->schedule?->estilo_id ?? $padrao;

        return $id ? StylePreset::query()->whereKey($id)->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $post->tenant_id))->first() : null;
    }

    /** @return array<int, string> temas dos ultimos 30 posts da conta (para nao repetir) */
    private function recentes(Post $post): array
    {
        return PostVersion::query()
            ->join('posts', 'posts.id', '=', 'post_versions.post_id')
            ->where('posts.account_id', $post->account_id)->where('posts.id', '<>', $post->id)
            // as outras pecas da mesma ideia (kit feed + stories) tem o mesmo tema de proposito
            ->when($post->idea_id, fn ($q) => $q->where(fn ($q) => $q->whereNull('posts.idea_id')->orWhere('posts.idea_id', '<>', $post->idea_id)))
            ->whereIn('posts.status', ['publicado', 'aprovado', 'aguardando_aprovacao', 'publicando'])
            ->orderByDesc('post_versions.id')->limit(30)
            ->get(['post_versions.saida_claude', 'post_versions.legenda'])
            ->map(fn ($v) => $v->saida_claude['tema'] ?? mb_substr((string) $v->legenda, 0, 80))
            ->filter()->unique()->values()->all();
    }
}
