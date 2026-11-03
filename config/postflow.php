<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Chave de criptografia dos tokens do Instagram
    |--------------------------------------------------------------------------
    |
    | Chave AES-256 separada da APP_KEY, guardada fora do banco (.env ou secret
    | do container). Gere com: php artisan postflow:token-key
    |
    */

    'token_key' => env('POSTFLOW_TOKEN_KEY'),

    'token_cipher' => 'AES-256-CBC',

    /*
    |--------------------------------------------------------------------------
    | Filas
    |--------------------------------------------------------------------------
    |
    | Cada etapa tem a sua fila e o seu worker (um container por tipo).
    | Só o worker da fila "publish" fala com a Meta.
    |
    */

    'queues' => [
        'content' => env('POSTFLOW_QUEUE_CONTENT', 'content'),
        'render' => env('POSTFLOW_QUEUE_RENDER', 'render'),
        'publish' => env('POSTFLOW_QUEUE_PUBLISH', 'publish'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Serviços internos
    |--------------------------------------------------------------------------
    |
    | Renderizador (Node + Playwright) e pré-processador (Python + rembg/Pillow),
    | chamados pelo worker da fila "render". Vazio = serviço não configurado.
    |
    */

    'services' => [
        'renderer_url' => env('POSTFLOW_RENDERER_URL'),
        // Hospedagem com cPanel (sem servico rodando): o renderizador roda por linha de comando.
        // Ex.: "/opt/alt/alt-nodejs22/root/usr/bin/node /home/conta/postflow/workers/renderer/cli.js"
        'renderer_cmd' => env('POSTFLOW_RENDERER_CMD'),
        'preprocessor_url' => env('POSTFLOW_PREPROCESSOR_URL'),
        'timeout' => (int) env('POSTFLOW_SERVICES_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Geracao com o Claude
    |--------------------------------------------------------------------------
    |
    | Modelo padrao: o mais barato (Claude Haiku 5.5). Troque por claude-sonnet-5-5 se a arte precisar de mais qualidade.
    | Precos em US$ por milhao de tokens, para o usage_log (confira na pagina de precos da Anthropic).
    |
    */

    'claude' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('POSTFLOW_CLAUDE_URL'), // so muda em testes (servidor falso)
        'modelo' => env('POSTFLOW_CLAUDE_MODELO', 'claude-haiku-5-5'),
        'esforco' => env('POSTFLOW_CLAUDE_ESFORCO', 'medium'),
        'max_tokens' => (int) env('POSTFLOW_CLAUDE_MAX_TOKENS', 16000),
        'precos' => [
            'claude-haiku-5-5' => ['entrada' => 0.10, 'saida' => 0.50],
            'claude-sonnet-5-5' => ['entrada' => 2.00, 'saida' => 10.00],
            'claude-opus-5-5' => ['entrada' => 4.00, 'saida' => 20.00],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ciclo automatico (php artisan postflow:ciclo, a cada minuto)
    |--------------------------------------------------------------------------
    */

    'ciclo' => [
        // Posts das agendas sao criados e gerados com esta antecedencia (da tempo de aprovar)
        'antecedencia_horas' => (int) env('POSTFLOW_ANTECEDENCIA_HORAS', 12),
        // Sem aprovacao ate N minutos antes do horario, o post expira (nunca se publica o que nao foi visto)
        'expira_minutos_antes' => (int) env('POSTFLOW_EXPIRA_MINUTOS', 60),
        // Quantos posts gerar por rodada (cada um chama o Claude e o renderizador)
        'gerar_por_rodada' => (int) env('POSTFLOW_GERAR_POR_RODADA', 2),
        // Esperas entre tentativas (minutos); depois da ultima, o post vai para "erro"
        'esperas' => [1, 5, 15],
        // Disco onde ficam as imagens (precisa ser publico: o Instagram baixa pela URL)
        'disco' => env('POSTFLOW_DISCO_MIDIA', 'public'),
    ],

];
