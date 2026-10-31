<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('instagram_accounts')->cascadeOnDelete();
            // Dias ISO (1 = segunda ... 7 = domingo).
            $table->jsonb('dias_semana');
            $table->time('horario');
            $table->string('formato')->default('feed');
            $table->string('objetivo')->nullable();
            $table->foreignId('estilo_id')->nullable()->constrained('style_presets')->nullOnDelete();
            $table->boolean('publicar_sem_revisao')->default(false);
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'ativo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
