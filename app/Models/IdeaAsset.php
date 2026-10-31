<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/** Tabela filha de ideas: o tenant vem da ideia. */
class IdeaAsset extends Pivot
{
    protected $table = 'idea_assets';

    public $incrementing = true;

    public const USOS = ['principal', 'fundo', 'logo', 'referencia'];
}
