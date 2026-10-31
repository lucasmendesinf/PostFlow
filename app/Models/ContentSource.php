<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ContentSource extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'tipo', 'origem', 'config', 'ultima_sincronizacao'];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'ultima_sincronizacao' => 'datetime',
        ];
    }
}
