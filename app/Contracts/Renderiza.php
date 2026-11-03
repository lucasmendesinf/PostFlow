<?php

namespace App\Contracts;

use App\Enums\PostFormat;

interface Renderiza
{
    /**
     * Transforma os slides (HTML + CSS do Claude) em JPEG.
     *
     * @param  array<int, array{html: string, css?: string}>  $slides
     * @param  array<string, string>  $assets  chave => data URI (usados como {{asset:chave}})
     * @return array<int, array{jpeg: string, problemas: array<int, string>}>
     */
    public function renderizar(PostFormat $formato, array $slides, array $assets = []): array;
}
