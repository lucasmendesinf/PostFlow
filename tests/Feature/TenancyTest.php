<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\StylePreset;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenancy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenancy = app(TenantContext::class);
    }

    public function test_consultas_filtram_pelo_tenant_atual(): void
    {
        [$calutec, $ja] = Tenant::factory()->count(2)->create();

        $this->tenancy->run($calutec, fn () => CatalogItem::create(['tipo' => 'produto', 'nome' => 'Site institucional']));
        $this->tenancy->run($ja, fn () => CatalogItem::create(['tipo' => 'produto', 'nome' => 'Narguilé']));

        $nomesCalutec = $this->tenancy->run($calutec, fn () => CatalogItem::pluck('nome')->all());
        $nomesJa = $this->tenancy->run($ja, fn () => CatalogItem::pluck('nome')->all());

        $this->assertSame(['Site institucional'], $nomesCalutec);
        $this->assertSame(['Narguilé'], $nomesJa);
    }

    public function test_tenant_id_e_preenchido_automaticamente(): void
    {
        $tenant = Tenant::factory()->create();

        $item = $this->tenancy->run($tenant, fn () => CatalogItem::create(['tipo' => 'evento', 'nome' => 'Show']));

        $this->assertSame($tenant->id, $item->tenant_id);
    }

    public function test_registro_de_outro_tenant_nao_e_encontrado_por_id(): void
    {
        [$a, $b] = Tenant::factory()->count(2)->create();
        $item = $this->tenancy->run($a, fn () => CatalogItem::create(['tipo' => 'produto', 'nome' => 'X']));

        $this->assertNull($this->tenancy->run($b, fn () => CatalogItem::find($item->id)));
    }

    public function test_presets_globais_aparecem_para_todos_e_os_proprios_so_para_o_dono(): void
    {
        [$a, $b] = Tenant::factory()->count(2)->create();

        StylePreset::create(['nome' => 'Minimalista', 'descricao_prompt' => 'Global']);
        $this->tenancy->run($a, fn () => StylePreset::create(['nome' => 'Neon da JA', 'descricao_prompt' => 'Próprio']));

        $this->assertEqualsCanonicalizing(
            ['Minimalista', 'Neon da JA'],
            $this->tenancy->run($a, fn () => StylePreset::pluck('nome')->all()),
        );
        $this->assertSame(['Minimalista'], $this->tenancy->run($b, fn () => StylePreset::pluck('nome')->all()));
    }

    public function test_run_restaura_o_tenant_anterior(): void
    {
        [$a, $b] = Tenant::factory()->count(2)->create();

        $this->tenancy->set($a);
        $this->tenancy->run($b, fn () => $this->assertSame($b->id, $this->tenancy->id()));

        $this->assertSame($a->id, $this->tenancy->id());
    }
}
