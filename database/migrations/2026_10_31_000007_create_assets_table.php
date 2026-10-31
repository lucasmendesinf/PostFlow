<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Identificador usado no HTML gerado pelo Claude: {{asset:chave}}.
            $table->string('chave');
            $table->string('origem');
            $table->string('tipo');
            $table->string('url_original');
            $table->string('url_processada')->nullable();
            $table->text('descricao')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'chave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
