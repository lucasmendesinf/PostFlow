<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('style_presets', function (Blueprint $table) {
            $table->id();
            // Nulo = preset global, disponível para todos os tenants.
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('nome');
            $table->text('descricao_prompt');
            $table->jsonb('paleta')->nullable();
            $table->jsonb('fontes')->nullable();
            $table->jsonb('elementos')->nullable();
            $table->jsonb('exemplos_html')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'ativo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('style_presets');
    }
};
