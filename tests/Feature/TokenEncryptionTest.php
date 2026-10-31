<?php

namespace Tests\Feature;

use App\Models\InstagramAccount;
use App\Support\TokenEncrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class TokenEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_access_token_fica_criptografado_no_banco_e_legivel_no_model(): void
    {
        $conta = InstagramAccount::factory()->create(['access_token' => 'IGAA-token-secreto']);

        $bruto = DB::table('instagram_accounts')->where('id', $conta->id)->value('access_token');

        $this->assertNotSame('IGAA-token-secreto', $bruto);
        $this->assertStringNotContainsString('token-secreto', $bruto);
        $this->assertSame('IGAA-token-secreto', $conta->fresh()->access_token);
    }

    public function test_token_usa_chave_propria_e_nao_a_app_key(): void
    {
        $conta = InstagramAccount::factory()->create(['access_token' => 'IGAA-abc']);
        $bruto = DB::table('instagram_accounts')->where('id', $conta->id)->value('access_token');

        $this->expectException(\Illuminate\Contracts\Encryption\DecryptException::class);
        Crypt::decryptString($bruto);
    }

    public function test_token_nao_aparece_na_serializacao(): void
    {
        $conta = InstagramAccount::factory()->create();

        $this->assertArrayNotHasKey('access_token', $conta->toArray());
    }

    public function test_sem_chave_configurada_falha_com_mensagem_clara(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('POSTFLOW_TOKEN_KEY');

        new TokenEncrypter(null);
    }
}
