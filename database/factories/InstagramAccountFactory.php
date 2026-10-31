<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\InstagramAccount>
 */
class InstagramAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'ig_user_id' => (string) fake()->unique()->numerify('17841##########'),
            'username' => fake()->userName(),
            'access_token' => 'IGAA'.fake()->sha256(),
            'token_expires_at' => now()->addDays(60),
            'status' => AccountStatus::Ativo,
        ];
    }
}
