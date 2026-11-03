<?php

namespace App\Services\Conteudo;

use App\Enums\PostFormat;
use App\Models\BrandProfile;
use App\Models\CatalogItem;
use App\Models\Post;
use App\Models\StylePreset;
use Carbon\CarbonInterface;

/**
 * Monta o pedido ao Claude em blocos: regras fixas (system, cacheado), marca, estilo e pedido do dia.
 * O Claude devolve o roteiro em JSON (schema em self::schema()); o servidor so transforma o HTML em imagem.
 */
class Prompt
{
    public const FONTES = ['Saira', 'Montserrat', 'Anton', 'Bebas Neue', 'Oswald', 'Inter'];

    private const DIAS = [1 => 'segunda-feira', 2 => 'terça-feira', 3 => 'quarta-feira', 4 => 'quinta-feira', 5 => 'sexta-feira', 6 => 'sábado', 7 => 'domingo'];

    private const OBJETIVOS = [
        'evento' => 'divulgar um evento', 'promocao' => 'divulgar uma promoção', 'novidade' => 'apresentar uma novidade',
        'combo' => 'divulgar um combo', 'aviso' => 'dar um aviso (horário, feriado)', 'engajamento' => 'gerar engajamento (pergunta, enquete na legenda)',
        'data_comemorativa' => 'aproveitar uma data comemorativa',
    ];

    public static function sistema(): string
    {
        $fontes = implode(', ', self::FONTES);

        return <<<TXT
Você é designer gráfico e social media de pequenos negócios brasileiros. Cria posts para o Instagram: a arte (em HTML e CSS) e a legenda, sempre em português do Brasil.

## Arte
- Cada slide é um trecho de HTML (sem <html>, <head> ou <body>) mais o CSS dele. O elemento raiz deve ter exatamente a largura e a altura do formato pedido, com position: relative e overflow: hidden.
- Tamanhos: feed e carrossel 1080x1350 px; stories 1080x1920 px. Nos stories, deixe 250 px livres em cima e embaixo (a interface do Instagram cobre essas faixas).
- Fontes disponíveis (só estas): {$fontes}. Use font-family com o nome exato.
- Nada de JavaScript, <img> ou url() com endereço da internet, @import ou fontes externas. Imagens só pelos assets informados, escritos como {{asset:chave}} no src de um <img> ou dentro de url() no CSS. Sem assets, faça a arte só com formas, cores, gradientes e tipografia (CSS puro).
- Texto grande e legível no celular: título com pelo menos 80 px, demais textos com pelo menos 36 px. Pouco texto na arte (até umas 25 palavras por slide); o resto vai na legenda.
- Bom contraste entre texto e fundo. Use as cores da marca. Nenhum texto pode sair do quadro.
- Carrossel: de 3 a 6 slides; o primeiro é a capa (chama a atenção) e o último traz a chamada para ação.

## Legenda
- Até 2.200 caracteres, no tom de voz da marca, com uma chamada para ação clara no final.
- Hashtags à parte, no campo hashtags (de 3 a 10, cada uma começando com #, sem repetir).
- alt_text: descrição curta da arte para acessibilidade.

## Regras
- Não invente preços, datas, horários, endereços, telefones ou promoções que não estejam no pedido ou no catálogo.
- Respeite as palavras proibidas da marca.
- Não repita o tema dos posts recentes listados.
- tema: resumo do assunto do post em poucas palavras (fica no histórico para não repetir).
TXT;
    }

    /** @return array<string, mixed> JSON schema da resposta */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tema' => ['type' => 'string'],
                'legenda' => ['type' => 'string'],
                'hashtags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'alt_text' => ['type' => 'string'],
                'slides' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['html' => ['type' => 'string'], 'css' => ['type' => 'string']],
                        'required' => ['html', 'css'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['tema', 'legenda', 'hashtags', 'alt_text', 'slides'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Texto do pedido (mensagem do usuario).
     *
     * @param  array<string, string>  $assets  chave => descricao
     * @param  array<int, string>  $recentes  temas/legendas recentes
     * @param  iterable<CatalogItem>  $catalogo
     */
    public static function pedido(Post $post, ?BrandProfile $marca, ?StylePreset $estilo, array $assets, array $recentes, iterable $catalogo, ?string $comentario = null, array $problemas = [], ?Conteudo $anterior = null): string
    {
        $tenant = $post->tenant;
        $tz = $tenant->timezone ?: 'America/Sao_Paulo';
        /** @var CarbonInterface $quando */
        $quando = $post->agendado_para->copy()->setTimezone($tz);
        $formato = $post->formato;
        [$w, $h] = $formato->dimensions();
        $l = [];

        $l[] = '# Marca';
        $l[] = "Nome: {$tenant->nome}";
        if ($marca) {
            $l[] = 'Segmento: '.($marca->segmento ?: 'não informado');
            if ($marca->tom_de_voz) $l[] = "Tom de voz: {$marca->tom_de_voz}";
            if ($marca->publico_alvo) $l[] = "Público-alvo: {$marca->publico_alvo}";
            if ($marca->cores) $l[] = 'Cores da marca: '.implode(', ', $marca->cores);
            if ($marca->fontes) $l[] = 'Fontes preferidas: '.implode(', ', array_intersect($marca->fontes, self::FONTES) ?: $marca->fontes).' (use só se estiverem na lista de fontes disponíveis)';
            if ($marca->palavras_proibidas) $l[] = 'Palavras proibidas: '.implode(', ', $marca->palavras_proibidas);
        }

        if ($estilo) {
            $l[] = '';
            $l[] = "# Estilo visual: {$estilo->nome}";
            $l[] = $estilo->descricao_prompt;
            if ($estilo->paleta) $l[] = 'Paleta: '.implode(', ', $estilo->paleta);
            if ($estilo->fontes) $l[] = 'Fontes: '.implode(', ', $estilo->fontes);
            if ($estilo->elementos) $l[] = 'Elementos: '.implode(', ', $estilo->elementos);
            foreach (array_filter($estilo->exemplos_html ?? []) as $i => $ex) {
                $l[] = 'Exemplo de referência '.($i + 1).' (inspire-se, não copie):';
                $l[] = $ex;
            }
        }

        $l[] = '';
        $l[] = '# Pedido';
        $l[] = 'Formato: '.match ($formato) {
            PostFormat::Feed => "feed (1 slide de {$w}x{$h})",
            PostFormat::Carrossel => "carrossel (3 a 6 slides de {$w}x{$h})",
            PostFormat::Stories => "stories (1 slide de {$w}x{$h})",
        };
        $l[] = 'Publicação: '.self::DIAS[$quando->isoWeekday()].', '.$quando->format('d/m/Y \à\s H:i');

        $ideia = $post->idea;
        $objetivo = $ideia?->objetivo ?? $post->schedule?->objetivo;
        if ($objetivo && isset(self::OBJETIVOS[$objetivo])) $l[] = 'Objetivo: '.self::OBJETIVOS[$objetivo];

        if ($ideia) {
            $l[] = 'O cliente pediu este post:';
            $l[] = $ideia->descricao ?: '(sem descrição; use o objetivo e o catálogo)';
            foreach (['texto_obrigatorio' => 'Texto que precisa aparecer na arte', 'cta' => 'Chamada para ação', 'link' => 'Link para citar na legenda'] as $k => $rotulo) {
                if (filled($ideia->campos_extras[$k] ?? null)) $l[] = "{$rotulo}: {$ideia->campos_extras[$k]}";
            }
        } else {
            $l[] = 'Post automático: escolha um tema útil para o público da marca, usando o catálogo abaixo, o dia da semana e datas comemorativas próximas (só se fizerem sentido para a marca).';
        }

        $itens = [];
        foreach ($catalogo as $c) {
            $p = $c->preco !== null ? ' — R$ '.number_format((float) $c->preco, 2, ',', '.') : '';
            $validade = $c->fim ? ' (válido até '.$c->fim->copy()->setTimezone($tz)->format('d/m').')' : '';
            $itens[] = "- [{$c->tipo}] {$c->nome}{$p}{$validade}".($c->descricao ? ": {$c->descricao}" : '');
        }
        if ($itens) {
            $l[] = '';
            $l[] = '# Catálogo da marca (preços e datas oficiais)';
            array_push($l, ...$itens);
        }

        $l[] = '';
        $l[] = '# Assets disponíveis';
        if ($assets) {
            foreach ($assets as $chave => $desc) $l[] = "- {{asset:{$chave}}}: {$desc}";
        } else {
            $l[] = 'Nenhum: faça a arte só com CSS.';
        }

        if ($recentes) {
            $l[] = '';
            $l[] = '# Posts recentes (não repita estes temas)';
            foreach ($recentes as $r) $l[] = "- {$r}";
        }

        if ($anterior) {
            $l[] = '';
            $l[] = '# Versão anterior deste post';
            $l[] = 'Tema: '.$anterior->tema;
            $l[] = 'Legenda: '.mb_substr($anterior->legenda, 0, 600);
        }
        if (filled($comentario)) {
            $l[] = '';
            $l[] = '# Ajuste pedido pelo cliente para esta nova versão';
            $l[] = $comentario;
        }
        if ($problemas) {
            $l[] = '';
            $l[] = '# Corrija estes problemas da arte anterior';
            foreach ($problemas as $p) $l[] = "- {$p}";
        }

        return implode("\n", $l);
    }
}
