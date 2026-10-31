<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;

/**
 * Tenant da requisição/job atual. Com tenant definido, todo model que usa
 * BelongsToTenant filtra e preenche tenant_id automaticamente.
 *
 * Sem tenant definido (agendador, comandos de sistema) não há filtro: o código
 * de sistema deve entrar no tenant com run() antes de mexer em dados de negócio.
 */
class TenantContext
{
    private ?int $tenantId = null;

    public function set(Tenant|int|null $tenant): void
    {
        $this->tenantId = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;
    }

    public function id(): ?int
    {
        return $this->tenantId;
    }

    public function has(): bool
    {
        return $this->tenantId !== null;
    }

    public function clear(): void
    {
        $this->tenantId = null;
    }

    /**
     * Executa o callback dentro do tenant informado e restaura o anterior.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(Tenant|int $tenant, callable $callback): mixed
    {
        $anterior = $this->tenantId;
        $this->set($tenant);

        try {
            return $callback();
        } finally {
            $this->tenantId = $anterior;
        }
    }
}
