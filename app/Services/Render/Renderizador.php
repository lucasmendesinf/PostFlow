<?php

namespace App\Services\Render;

use App\Contracts\Renderiza;
use App\Enums\PostFormat;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Chama o renderizador Node (workers/renderer): por HTTP (Docker/VPS, POSTFLOW_RENDERER_URL)
 * ou por linha de comando (cPanel, POSTFLOW_RENDERER_CMD). A linha de comando tem prioridade.
 */
class Renderizador implements Renderiza
{
    public function __construct(private readonly array $config) {}

    public function renderizar(PostFormat $formato, array $slides, array $assets = []): array
    {
        $pedido = ['formato' => $formato->value, 'slides' => array_values($slides), 'assets' => (object) $assets];
        $timeout = max(30, (int) ($this->config['timeout'] ?? 60));

        if (filled($this->config['renderer_cmd'] ?? null)) {
            $r = Process::timeout($timeout)->input(json_encode($pedido))->run($this->config['renderer_cmd']);
            $dados = json_decode($r->output(), true);
            if (! $r->successful() || ! is_array($dados) || isset($dados['erro'])) {
                throw new RuntimeException('Renderizador falhou: '.($dados['erro'] ?? trim($r->errorOutput()) ?: 'sem resposta'));
            }
        } elseif (filled($this->config['renderer_url'] ?? null)) {
            $resp = Http::timeout($timeout)->acceptJson()->post(rtrim($this->config['renderer_url'], '/').'/render', $pedido);
            $dados = $resp->json();
            if ($resp->failed() || ! is_array($dados) || isset($dados['erro'])) {
                throw new RuntimeException('Renderizador falhou: '.($dados['erro'] ?? 'HTTP '.$resp->status()));
            }
        } else {
            throw new RuntimeException('Renderizador não configurado: defina POSTFLOW_RENDERER_CMD (cPanel) ou POSTFLOW_RENDERER_URL.');
        }

        return array_map(fn (array $i) => [
            'jpeg' => base64_decode($i['jpeg_base64'] ?? '', true) ?: throw new RuntimeException('Renderizador devolveu imagem inválida.'),
            'problemas' => array_values($i['problemas'] ?? []),
        ], $dados['imagens'] ?? []);
    }
}
