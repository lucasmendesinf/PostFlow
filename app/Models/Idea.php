<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Idea extends Model
{
    use BelongsToTenant;

    public const MODOS = ['livre', 'guiado'];

    protected $fillable = [
        'tenant_id', 'account_id', 'modo', 'data_publicacao', 'horario', 'formatos', 'objetivo',
        'estilo_id', 'descricao', 'campos_extras', 'sequencia', 'status',
    ];

    protected function casts(): array
    {
        return [
            'data_publicacao' => 'date',
            'formatos' => 'array',
            'campos_extras' => 'array',
            'sequencia' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(InstagramAccount::class, 'account_id');
    }

    public function estilo(): BelongsTo
    {
        return $this->belongsTo(StylePreset::class, 'estilo_id');
    }

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'idea_assets')
            ->using(IdeaAsset::class)
            ->withPivot(['ordem', 'uso'])
            ->withTimestamps()
            ->orderByPivot('ordem');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
