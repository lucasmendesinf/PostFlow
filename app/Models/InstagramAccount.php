<?php

namespace App\Models;

use App\Casts\EncryptedToken;
use App\Enums\AccountStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstagramAccount extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'ig_user_id', 'username', 'access_token', 'token_expires_at', 'status'];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'access_token' => EncryptedToken::class,
            'token_expires_at' => 'datetime',
            'status' => AccountStatus::class,
        ];
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class, 'account_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'account_id');
    }
}
