<?php

namespace Database\Factories;

use App\Enums\PostFormat;
use App\Enums\PostStatus;
use App\Models\InstagramAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Post>
 */
class PostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_id' => InstagramAccount::factory(),
            'tenant_id' => fn (array $attrs) => InstagramAccount::withoutGlobalScopes()->find($attrs['account_id'])->tenant_id,
            'formato' => PostFormat::Feed,
            'status' => PostStatus::Agendado,
            'agendado_para' => now()->addDay(),
        ];
    }
}
