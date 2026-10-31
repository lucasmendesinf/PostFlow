<?php

namespace App\Models;

use App\Enums\PostFormat;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Schedule extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'account_id', 'dias_semana', 'horario', 'formato', 'objetivo',
        'estilo_id', 'publicar_sem_revisao', 'ativo',
    ];

    protected function casts(): array
    {
        return [
            'dias_semana' => 'array',
            'formato' => PostFormat::class,
            'publicar_sem_revisao' => 'boolean',
            'ativo' => 'boolean',
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

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
