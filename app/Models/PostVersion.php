<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tabela filha de posts: o tenant vem do post. */
class PostVersion extends Model
{
    public const CREATED_AT = 'criado_em';

    public const UPDATED_AT = null;

    protected $fillable = ['post_id', 'versao', 'saida_claude', 'legenda', 'media_urls', 'comentario'];

    protected function casts(): array
    {
        return [
            'saida_claude' => 'array',
            'media_urls' => 'array',
            'criado_em' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
