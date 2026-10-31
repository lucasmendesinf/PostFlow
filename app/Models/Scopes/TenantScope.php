<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(TenantContext::class)->id();

        if ($tenantId === null) {
            return;
        }

        $coluna = $model->qualifyColumn('tenant_id');

        // Registros globais (tenant_id nulo), como os style_presets do admin.
        if (property_exists($model, 'tenantIncludesGlobal') && $model->tenantIncludesGlobal) {
            $builder->where(fn (Builder $q) => $q->where($coluna, $tenantId)->orWhereNull($coluna));

            return;
        }

        $builder->where($coluna, $tenantId);
    }
}
