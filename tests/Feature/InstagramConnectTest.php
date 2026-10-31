<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\InstagramAccount;
use App\Models\Tenant;
use App\Services\Instagram\OAuthState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class InstagramConnectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.instagram.client_id' => '123456',
            'services.instagram.client_secret' => 'segredo-do-app',
            'services.instagram.redirect_uri' => 'https://postflow.test/api/v1/instagram/callback',
        ]);
    }

    private function fakeMeta(): void
    {
        Http::fake([
            'api.instagram.com/oauth/access_token' => Http::response([
                'data' => [['access_token' => 'IGAA-curto', 'user_id' => '9001', 'permissions' => 'instagram_business_basic']],
            ]),
            'graph.instagram.com/access_token*' => Http::response([
                'access_token' => 'IGAA-longo', 'token_type' => 'bearer', 'expires_in' => 5184000,
            ]),
            'graph.instagram.com/v23.0/me*' => Http::response(['user_id' => '17841400000000001', 'username' => 'calutec']),
        ]);
    }

    public function test_connect_com_link_assinado_redireciona_para_o_consentimento_da_meta(): void
    {
        $tenant = Tenant::factory()->create();
        $url = URL::temporarySignedRoute('instagram.connect', now()->addMinutes(5), ['tenant' => $tenant->id]);

        $resposta = $this->get($url);

        $resposta->assertRedirect();
        $destino = $resposta->headers->get('Location');
        $this->assertStringStartsWith('https://www.instagram.com/oauth/authorize?', $destino);
        parse_str(parse_url($destino, PHP_URL_QUERY), $query);
        $this->assertSame('123456', $query['client_id']);
        $this->assertSame('instagram_business_basic,instagram_business_content_publish', $query['scope']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame($tenant->id, app(OAuthState::class)->consume($query['state']));
    }

    public function test_connect_sem_assinatura_nem_usuario_e_recusado(): void
    {
        $tenant = Tenant::factory()->create();

        $this->getJson("/api/v1/instagram/connect?tenant={$tenant->id}")->assertUnauthorized();
    }

    public function test_callback_grava_token_longo_criptografado_e_perfil(): void
    {
        $this->fakeMeta();
        $tenant = Tenant::factory()->create();
        $state = app(OAuthState::class)->issue($tenant->id);

        $this->getJson("/api/v1/instagram/callback?code=CODIGO123%23_&state={$state}")
            ->assertOk()
            ->assertJsonPath('conta.username', 'calutec')
            ->assertJsonMissingPath('conta.access_token');

        $conta = InstagramAccount::sole();
        $this->assertSame($tenant->id, $conta->tenant_id);
        $this->assertSame('17841400000000001', $conta->ig_user_id);
        $this->assertSame('IGAA-longo', $conta->access_token);
        $this->assertSame(AccountStatus::Ativo, $conta->status);
        $this->assertTrue($conta->token_expires_at->between(now()->addDays(59), now()->addDays(61)));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.instagram.com/oauth/access_token')
            && $r['code'] === 'CODIGO123'
            && $r['grant_type'] === 'authorization_code');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'grant_type=ig_exchange_token'));
    }

    public function test_reconectar_atualiza_a_conta_existente_e_volta_para_ativo(): void
    {
        $this->fakeMeta();
        $tenant = Tenant::factory()->create();
        InstagramAccount::factory()->for($tenant)->create([
            'ig_user_id' => '17841400000000001', 'access_token' => 'IGAA-velho', 'status' => AccountStatus::Reconectar,
        ]);

        $state = app(OAuthState::class)->issue($tenant->id);
        $this->getJson("/api/v1/instagram/callback?code=X&state={$state}")->assertOk();

        $conta = InstagramAccount::sole();
        $this->assertSame('IGAA-longo', $conta->access_token);
        $this->assertSame(AccountStatus::Ativo, $conta->status);
    }

    public function test_state_e_de_uso_unico(): void
    {
        $this->fakeMeta();
        $state = app(OAuthState::class)->issue(Tenant::factory()->create()->id);

        $this->getJson("/api/v1/instagram/callback?code=X&state={$state}")->assertOk();
        $this->getJson("/api/v1/instagram/callback?code=X&state={$state}")->assertStatus(400);
    }

    public function test_state_invalido_e_recusado_sem_chamar_a_meta(): void
    {
        Http::fake();

        $this->getJson('/api/v1/instagram/callback?code=X&state=forjado')->assertStatus(400);

        Http::assertNothingSent();
        $this->assertSame(0, InstagramAccount::count());
    }

    public function test_usuario_que_nega_o_consentimento_recebe_erro_claro(): void
    {
        $this->getJson('/api/v1/instagram/callback?error=access_denied&error_reason=user_denied&error_description=Permissions+error')
            ->assertStatus(400)
            ->assertJsonPath('detalhe', 'Permissions error');
    }

    public function test_erro_da_meta_na_troca_do_codigo_vira_502(): void
    {
        Http::fake([
            'api.instagram.com/*' => Http::response(['error_type' => 'OAuthException', 'code' => 400, 'error_message' => 'Invalid authorization code'], 400),
        ]);
        $state = app(OAuthState::class)->issue(Tenant::factory()->create()->id);

        $this->getJson("/api/v1/instagram/callback?code=X&state={$state}")
            ->assertStatus(502)
            ->assertJsonPath('detalhe', 'Invalid authorization code');
    }

    public function test_comando_gera_link_assinado_pelo_nome_do_tenant(): void
    {
        Tenant::factory()->create(['nome' => 'Calutec']);

        $this->artisan('postflow:instagram-connect-url', ['tenant' => 'calutec'])
            ->expectsOutputToContain('/api/v1/instagram/connect?')
            ->assertSuccessful();
    }
}
