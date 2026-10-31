<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageLog extends Model
{
    use BelongsToTenant;

    protected $table = 'usage_log';

    public const CREATED_AT = 'criado_em';

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'post_id', 'modelo', 'tokens_entrada', 'tokens_saida', 'custo_usd'];

    protected function casts(): array
    {
        return [
            'tokens_entrada' => 'integer',
            'tokens_saida' => 'integer',
            'custo_usd' => 'decimal:6',
            'criado_em' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
