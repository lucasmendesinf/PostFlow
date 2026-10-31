<?php

namespace App\Http\Controllers;

use App\Services\Instagram\InstagramAccountService;
use App\Services\Instagram\InstagramApiException;
use App\Services\Instagram\InstagramClient;
use App\Services\Instagram\OAuthState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InstagramOAuthController extends Controller
{
    /**
     * GET /api/v1/instagram/connect
     *
     * O tenant vem do usuário autenticado ou, na fase 1 (sem painel), de um link
     * assinado gerado por `php artisan postflow:instagram-connect-url`.
     */
    public function connect(Request $request, InstagramClient $client, OAuthState $state): RedirectResponse|JsonResponse
    {
        $tenantId = $request->hasValidSignature()
            ? (int) $request->query('tenant')
            : $request->user()?->tenant_id;

        if (! $tenantId) {
            return response()->json(['erro' => 'Não autenticado ou link de conexão inválido/expirado.'], 401);
        }

        return redirect()->away($client->authorizationUrl($state->issue($tenantId)));
    }

    /** GET /api/v1/instagram/callback */
    public function callback(Request $request, OAuthState $state, InstagramAccountService $contas): JsonResponse
    {
        if ($request->filled('error')) {
            return response()->json([
                'erro' => 'Conexão cancelada ou negada no Instagram.',
                'detalhe' => $request->query('error_description') ?? $request->query('error_reason'),
            ], 400);
        }

        $tenantId = $state->consume((string) $request->query('state', ''));
        if ($tenantId === null) {
            return response()->json(['erro' => 'Parâmetro state inválido ou expirado. Inicie a conexão de novo.'], 400);
        }

        if (! $request->filled('code')) {
            return response()->json(['erro' => 'O Instagram não devolveu o código de autorização.'], 400);
        }

        try {
            $conta = $contas->connect($tenantId, (string) $request->query('code'));
        } catch (InstagramApiException $e) {
            Log::error('Falha ao conectar conta do Instagram', ['tenant_id' => $tenantId, 'erro' => $e->getMessage(), 'codigo' => $e->apiCode]);

            return response()->json(['erro' => 'O Instagram recusou a conexão.', 'detalhe' => $e->getMessage()], 502);
        }

        return response()->json([
            'mensagem' => "Conta @{$conta->username} conectada.",
            'conta' => $conta->only(['id', 'ig_user_id', 'username', 'status', 'token_expires_at']),
        ]);
    }
}
