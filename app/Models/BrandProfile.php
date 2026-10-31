<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandProfile extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'segmento', 'logo_url', 'cores', 'fontes', 'tom_de_voz',
        'publico_alvo', 'palavras_proibidas', 'estilo_padrao_id',
    ];

    protected function casts(): array
    {
        return [
            'cores' => 'array',
            'fontes' => 'array',
            'palavras_proibidas' => 'array',
        ];
    }

    public function estiloPadrao(): BelongsTo
    {
        return $this->belongsTo(StylePreset::class, 'estilo_padrao_id');
    }
}
