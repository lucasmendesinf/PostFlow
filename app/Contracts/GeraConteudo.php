<?php

namespace App\Contracts;

use App\Models\Post;
use App\Services\Conteudo\Conteudo;

interface GeraConteudo
{
    /**
     * Gera o roteiro do post (tema, legenda, hashtags e os slides em HTML).
     *
     * @param  array<string, string>  $assets  chave => descricao (o que o Claude pode usar como {{asset:chave}})
     * @param  array<int, string>  $problemas  problemas de layout da tentativa anterior, para corrigir
     */
    public function gerar(Post $post, array $assets, ?string $comentario = null, array $problemas = [], ?Conteudo $anterior = null): Conteudo;
}
