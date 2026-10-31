<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Asset extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'chave', 'origem', 'tipo', 'url_original', 'url_processada', 'descricao'];

    public function ideas(): BelongsToMany
    {
        return $this->belongsToMany(Idea::class, 'idea_assets')
            ->using(IdeaAsset::class)
            ->withPivot(['ordem', 'uso'])
            ->withTimestamps();
    }

    /** Referência usada no HTML gerado pelo Claude. */
    public function placeholder(): string
    {
        return '{{asset:'.$this->chave.'}}';
    }
}
