<?php

namespace App\Services\Instagram;

use App\Enums\PostFormat;
use App\Models\InstagramAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Publicacao pela Instagram API com Business Login for Instagram:
 * cria o container (feed, itens do carrossel + CAROUSEL, ou STORIES), espera ficar pronto e chama o media_publish.
 * Antes, confere o limite de publicacoes das ultimas 24 h (content_publishing_limit).
 */
class InstagramPublisher
{
    /** @var callable(int): void */
    private $esperar;

    public function __construct(private readonly array $config, ?callable $esperar = null)
    {
        $this->esperar = $esperar ?? fn (int $s) => sleep($s);
    }

    /**
     * @param  array<int, string>  $imagens  URLs publicas dos JPEG (o Instagram baixa de la)
     * @return array{container_id: string, media_id: string}
     */
    public function publicar(InstagramAccount $conta, PostFormat $formato, array $imagens, string $legenda): array
    {
        if (! $imagens) {
            throw new InstagramApiException('O post não tem imagens para publicar.');
        }
        $this->conferirLimite($conta);

        if ($formato === PostFormat::Carrossel && count($imagens) >= 2) {
            $filhos = [];
            foreach (array_slice($imagens, 0, 10) as $url) {
                $filhos[] = $this->criarContainer($conta, ['image_url' => $url, 'is_carousel_item' => 'true']);
            }
            foreach ($filhos as $f) {
                $this->aguardarPronto($conta, $f);
            }
            $container = $this->criarContainer($conta, ['media_type' => 'CAROUSEL', 'children' => implode(',', $filhos), 'caption' => $legenda]);
        } elseif ($formato === PostFormat::Stories) {
            $container = $this->criarContainer($conta, ['media_type' => 'STORIES', 'image_url' => $imagens[0]]);
        } else {
            $container = $this->criarContainer($conta, ['image_url' => $imagens[0], 'caption' => $legenda]);
        }

        $this->aguardarPronto($conta, $container);
        $media = $this->json($this->http()->asForm()->post($this->url($conta->ig_user_id.'/media_publish'), [
            'creation_id' => $container,
            'access_token' => $conta->access_token,
        ]));

        return ['container_id' => $container, 'media_id' => (string) $media['id']];
    }

    /** Link do post publicado (permalink), para o historico. */
    public function permalink(InstagramAccount $conta, string $mediaId): ?string
    {
        try {
            return $this->json($this->http()->get($this->url($mediaId), ['fields' => 'permalink', 'access_token' => $conta->access_token]))['permalink'] ?? null;
        } catch (InstagramApiException) {
            return null;
        }
    }

    public function conferirLimite(InstagramAccount $conta): void
    {
        $d = $this->json($this->http()->get($this->url($conta->ig_user_id.'/content_publishing_limit'), [
            'fields' => 'quota_usage,config',
            'access_token' => $conta->access_token,
        ]));
        $uso = (int) ($d['data'][0]['quota_usage'] ?? 0);
        $total = (int) ($d['data'][0]['config']['quota_total'] ?? 100);
        if ($uso >= $total) {
            throw new LimitePublicacaoAtingido("Limite de {$total} publicações em 24 h atingido nesta conta.");
        }
    }

    private function criarContainer(InstagramAccount $conta, array $campos): string
    {
        $d = $this->json($this->http()->asForm()->post($this->url($conta->ig_user_id.'/media'), $campos + ['access_token' => $conta->access_token]));

        return (string) $d['id'];
    }

    // Imagens costumam ficar prontas na hora; confere o status por ate ~1 min (FINISHED / ERROR / EXPIRED)
    private function aguardarPronto(InstagramAccount $conta, string $container): void
    {
        for ($i = 0; $i < 12; $i++) {
            $d = $this->json($this->http()->get($this->url($container), ['fields' => 'status_code,status', 'access_token' => $conta->access_token]));
            $s = $d['status_code'] ?? 'FINISHED';
            if ($s === 'FINISHED' || $s === 'PUBLISHED') {
                return;
            }
            if (in_array($s, ['ERROR', 'EXPIRED'], true)) {
                throw new InstagramApiException('O Instagram recusou a mídia: '.($d['status'] ?? $s));
            }
            ($this->esperar)(5);
        }
        throw new InstagramApiException('O Instagram demorou demais para processar a mídia.');
    }

    private function url(string $caminho): string
    {
        return rtrim($this->config['graph_url'] ?? 'https://graph.instagram.com', '/').'/'.$this->config['graph_version'].'/'.$caminho;
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->timeout(30)->connectTimeout(10);
    }

    private function json(Response $r): array
    {
        if ($r->failed() || isset($r->json()['error'])) {
            throw InstagramApiException::fromResponse($r);
        }

        return $r->json() ?? [];
    }
}
