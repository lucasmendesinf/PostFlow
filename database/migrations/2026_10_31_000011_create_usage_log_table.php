<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('post_id')->nullable()->constrained('posts')->nullOnDelete();
            $table->string('modelo')->nullable();
            $table->unsignedInteger('tokens_entrada')->default(0);
            $table->unsignedInteger('tokens_saida')->default(0);
            $table->decimal('custo_usd', 12, 6)->default(0);
            $table->timestampTz('criado_em')->useCurrent();

            $table->index(['tenant_id', 'criado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_log');
    }
};
