<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    private const TABELAS = [
        'tenants', 'brand_profiles', 'instagram_accounts', 'catalog_items', 'content_sources',
        'style_presets', 'assets', 'schedules', 'ideas', 'idea_assets', 'posts', 'post_versions',
        'post_events', 'usage_log',
    ];

    public function test_todas_as_tabelas_do_modelo_de_dados_existem(): void
    {
        foreach (self::TABELAS as $tabela) {
            $this->assertTrue(Schema::hasTable($tabela), "Tabela {$tabela} não existe");
        }
    }

    public function test_tabelas_de_negocio_carregam_tenant_id(): void
    {
        $filhas = ['tenants', 'idea_assets', 'post_versions', 'post_events'];

        foreach (array_diff(self::TABELAS, $filhas) as $tabela) {
            $this->assertTrue(Schema::hasColumn($tabela, 'tenant_id'), "{$tabela} sem tenant_id");
        }

        $this->assertTrue(Schema::hasColumn('users', 'tenant_id'));
    }

    public function test_migrations_revertem_e_rodam_de_novo_do_zero(): void
    {
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--force' => true]));
        $this->assertFalse(Schema::hasTable('posts'));

        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertTrue(Schema::hasTable('posts'));
    }
}
