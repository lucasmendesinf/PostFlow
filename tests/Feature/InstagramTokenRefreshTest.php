<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Jobs\Instagram\RefreshInstagramTokenJob;
use App\Models\InstagramAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\InstagramReconnectNeeded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InstagramTokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    private function fakeRefreshOk(): void
    {
        Http::fake([
            'graph.instagram.com/refresh_access_token*' => Http::response([
                'access_token' => 'IGAA-renovado', 'token_type' => 'bearer', 'expires_in' => 5184000,
            ]),
        ]);
    }

    public function test_agendamento_so_enfileira_contas_ativas_que_vencem_em_menos_de_10_dias(): void
    {
        Queue::fake();
        $vence = InstagramAccount::factory()->create(['token_expires_at' => now()->addDays(5)]);
        InstagramAccount::factory()->create(['token_expires_at' => now()->addDays(40)]);
        InstagramAccount::factory()->create(['token_expires_at' => now()->addDays(3), 'status' => AccountStatus::Reconectar]);

        $this->artisan('postflow:instagram-refresh')->assertSuccessful();

        Queue::assertPushedOn('publish', RefreshInstagramTokenJob::class, fn ($job) => $job->accountId === $vence->id);
        Queue::assertCount(1);
    }

    public function test_renovacao_forcada_por_comando_troca_o_token_e_estende_o_prazo(): void
    {
        $this->fakeRefreshOk();
        $conta = InstagramAccount::factory()->create(['access_token' => 'IGAA-atual', 'token_expires_at' => now()->addDays(50)]);

        $this->artisan('postflow:instagram-refresh', ['--account' => $conta->id, '--force' => true, '--sync' => true])
            ->expectsOutputToContain('agora vence em')
            ->assertSuccessful();

        $conta->refresh();
        $this->assertSame('IGAA-renovado', $conta->access_token);
        $this->assertTrue($conta->token_expires_at->greaterThan(now()->addDays(59)));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'grant_type=ig_refresh_token') && $r['access_token'] === 'IGAA-atual');
    }

    public function test_token_revogado_marca_reconectar_e_avisa_o_cliente_sem_novas_tentativas(): void
    {
        Notification::fake();
        Http::fake([
            'graph.instagram.com/*' => Http::response([
                'error' => ['message' => 'Error validating access token: The user has not authorized application.', 'type' => 'OAuthException', 'code' => 190],
            ], 400),
        ]);
        $tenant = Tenant::factory()->create();
        $dono = User::factory()->create(['tenant_id' => $tenant->id]);
        $conta = InstagramAccount::factory()->for($tenant)->create(['token_expires_at' => now()->addDays(2)]);

        $this->artisan('postflow:instagram-refresh', ['--sync' => true])->assertFailed();

        $this->assertSame(AccountStatus::Reconectar, $conta->fresh()->status);
        Notification::assertSentTo($dono, InstagramReconnectNeeded::class, fn ($n) => str_contains($n->motivo, 'not authorized'));
        Notification::assertCount(1);
        Http::assertSentCount(1);
    }

    public function test_token_ja_vencido_marca_reconectar_sem_chamar_a_meta(): void
    {
        Http::fake();
        $conta = InstagramAccount::factory()->create(['token_expires_at' => now()->subDay()]);

        RefreshInstagramTokenJob::dispatchSync($conta->id);

        $this->assertSame(AccountStatus::Reconectar, $conta->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_erro_temporario_sobe_para_a_fila_tentar_de_novo_sem_marcar_a_conta(): void
    {
        Http::fake(['graph.instagram.com/*' => Http::response(['error' => ['message' => 'Application request limit reached', 'code' => 4]], 400)]);
        $conta = InstagramAccount::factory()->create(['token_expires_at' => now()->addDays(2)]);

        $job = new RefreshInstagramTokenJob($conta->id);

        try {
            app()->call([$job, 'handle']);
            $this->fail('Era esperada uma exceção para a fila tentar de novo.');
        } catch (\App\Services\Instagram\InstagramApiException $e) {
            $this->assertFalse($e->isTokenInvalid());
        }

        $this->assertSame(AccountStatus::Ativo, $conta->fresh()->status);
    }

    public function test_falha_depois_das_tentativas_marca_reconectar(): void
    {
        Notification::fake();
        $conta = InstagramAccount::factory()->create();

        (new RefreshInstagramTokenJob($conta->id))->failed(new \RuntimeException('Timeout'));

        $this->assertSame(AccountStatus::Reconectar, $conta->fresh()->status);
    }

    public function test_renovacao_diaria_esta_agendada(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('postflow:instagram-refresh')->assertSuccessful();
    }
}
