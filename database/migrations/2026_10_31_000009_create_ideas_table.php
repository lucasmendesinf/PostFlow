<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ideas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('instagram_accounts')->cascadeOnDelete();
            $table->string('modo');
            $table->date('data_publicacao');
            $table->time('horario');
            $table->jsonb('formatos');
            $table->string('objetivo')->nullable();
            $table->foreignId('estilo_id')->nullable()->constrained('style_presets')->nullOnDelete();
            $table->text('descricao')->nullable();
            $table->jsonb('campos_extras')->nullable();
            $table->jsonb('sequencia')->nullable();
            $table->string('status')->default('pendente');
            $table->timestamps();

            $table->index(['tenant_id', 'data_publicacao']);
        });

        Schema::create('idea_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('idea_id')->constrained('ideas')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->string('uso')->default('principal');
            $table->timestamps();

            $table->unique(['idea_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idea_assets');
        Schema::dropIfExists('ideas');
    }
};
