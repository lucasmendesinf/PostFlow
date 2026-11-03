<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // Tentativas da etapa atual (geracao ou publicacao) e quando tentar de novo
            $table->unsignedSmallInteger('tentativas')->default(0);
            $table->timestampTz('proxima_tentativa_em')->nullable();
            // Pedido de nova versao vindo do painel ("mais destaque no preco")
            $table->text('comentario_pendente')->nullable();
            $table->index(['status', 'proxima_tentativa_em']);
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['status', 'proxima_tentativa_em']);
            $table->dropColumn(['tentativas', 'proxima_tentativa_em', 'comentario_pendente']);
        });
    }
};
