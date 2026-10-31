<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class StylePreset extends Model
{
    use BelongsToTenant;

    /** Dentro de um tenant, enxerga os presets dele e os globais. */
    public bool $tenantIncludesGlobal = true;

    protected $fillable = [
        'tenant_id', 'nome', 'descricao_prompt', 'paleta', 'fontes', 'elementos', 'exemplos_html', 'ativo',
    ];

    protected function casts(): array
    {
        return [
            'paleta' => 'array',
            'fontes' => 'array',
            'elementos' => 'array',
            'exemplos_html' => 'array',
            'ativo' => 'boolean',
        ];
    }

    public function isGlobal(): bool
    {
        return $this->tenant_id === null;
    }
}
