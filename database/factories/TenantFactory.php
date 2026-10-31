<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Tenant>
 */
class TenantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nome' => fake()->company(),
            'plano' => 'interno',
            'status' => 'ativo',
            'timezone' => 'America/Sao_Paulo',
        ];
    }
}
