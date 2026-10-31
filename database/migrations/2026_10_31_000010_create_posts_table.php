<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('instagram_accounts')->cascadeOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('schedules')->nullOnDelete();
            $table->foreignId('idea_id')->nullable()->constrained('ideas')->nullOnDelete();
            $table->string('formato');
            $table->string('status')->default('agendado');
            $table->unsignedBigInteger('versao_aprovada_id')->nullable();
            $table->timestampTz('agendado_para');
            $table->string('ig_container_id')->nullable();
            $table->string('ig_media_id')->nullable();
            $table->timestampTz('publicado_em')->nullable();
            $table->text('erro')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'agendado_para']);
        });

        Schema::create('post_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->unsignedInteger('versao');
            $table->jsonb('saida_claude')->nullable();
            $table->text('legenda')->nullable();
            $table->jsonb('media_urls')->nullable();
            $table->text('comentario')->nullable();
            $table->timestampTz('criado_em')->useCurrent();

            $table->unique(['post_id', 'versao']);
        });

        // posts <-> post_versions se referenciam; a FK da versão aprovada entra depois das duas tabelas.
        Schema::table('posts', function (Blueprint $table) {
            $table->foreign('versao_aprovada_id')->references('id')->on('post_versions')->nullOnDelete();
        });

        Schema::create('post_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->string('status_anterior')->nullable();
            $table->string('status_novo');
            $table->text('detalhe')->nullable();
            $table->timestampTz('criado_em')->useCurrent();

            $table->index(['post_id', 'criado_em']);
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropForeign(['versao_aprovada_id']);
        });

        Schema::dropIfExists('post_events');
        Schema::dropIfExists('post_versions');
        Schema::dropIfExists('posts');
    }
};
