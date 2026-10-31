<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CatalogItem extends Model
{
    use BelongsToTenant;

    public const TIPOS = ['produto', 'evento', 'promocao'];

    protected $fillable = ['tenant_id', 'tipo', 'nome', 'descricao', 'preco', 'imagem_url', 'inicio', 'fim', 'ativo'];

    protected function casts(): array
    {
        return [
            'preco' => 'decimal:2',
            'inicio' => 'datetime',
            'fim' => 'datetime',
            'ativo' => 'boolean',
        ];
    }
}
