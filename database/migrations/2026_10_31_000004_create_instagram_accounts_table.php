<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('ig_user_id');
            $table->string('username')->nullable();
            // Criptografado em repouso (AES-256) com chave fora do banco: ver App\Casts\EncryptedToken.
            $table->text('access_token')->nullable();
            $table->timestampTz('token_expires_at')->nullable();
            $table->string('status')->default('ativo');
            $table->timestamps();

            $table->unique(['tenant_id', 'ig_user_id']);
            $table->index(['status', 'token_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_accounts');
    }
};
