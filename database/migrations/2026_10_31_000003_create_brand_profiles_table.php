<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('segmento')->nullable();
            $table->string('logo_url')->nullable();
            $table->jsonb('cores')->nullable();
            $table->jsonb('fontes')->nullable();
            $table->text('tom_de_voz')->nullable();
            $table->text('publico_alvo')->nullable();
            $table->jsonb('palavras_proibidas')->nullable();
            $table->foreignId('estilo_padrao_id')->nullable()->constrained('style_presets')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_profiles');
    }
};
