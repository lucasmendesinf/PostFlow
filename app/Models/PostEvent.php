<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tabela filha de posts: o tenant vem do post. */
class PostEvent extends Model
{
    public const CREATED_AT = 'criado_em';

    public const UPDATED_AT = null;

    protected $fillable = ['post_id', 'status_anterior', 'status_novo', 'detalhe'];

    protected function casts(): array
    {
        return [
            'criado_em' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
