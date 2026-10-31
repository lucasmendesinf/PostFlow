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
        'preprocessor_url' => env('POSTFLOW_PREPROCESSOR_URL'),
        'timeout' => (int) env('POSTFLOW_SERVICES_TIMEOUT', 60),
    ],

];
