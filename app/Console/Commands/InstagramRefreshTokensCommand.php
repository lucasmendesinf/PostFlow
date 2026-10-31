<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Jobs\Instagram\RefreshInstagramTokenJob;
use App\Models\InstagramAccount;
use Illuminate\Console\Command;
use Throwable;

class InstagramRefreshTokensCommand extends Command
{
    protected $signature = 'postflow:instagram-refresh
        {--account= : Renova só esta conta (id)}
        {--force : Ignora a janela de vencimento e renova mesmo que falte muito}
        {--sync : Renova agora, sem passar pela fila, e mostra o resultado}';

    protected $description = 'Renova os tokens do Instagram que vencem em menos de N dias (roda diariamente pelo agendador)';

    public function handle(): int
    {
        $janela = (int) config('services.instagram.refresh_window_days');

        $contas = InstagramAccount::query()
            ->where('status', AccountStatus::Ativo)
            ->when($this->option('account'), fn ($q, $id) => $q->whereKey($id))
            ->when(! $this->option('force'), fn ($q) => $q->where('token_expires_at', '<', now()->addDays($janela)))
            ->orderBy('id')
            ->get();

        if ($contas->isEmpty()) {
            $this->info($this->option('account')
                ? 'Conta não encontrada, não está ativa ou não vence dentro da janela (use --force).'
                : "Nenhum token vence nos próximos {$janela} dias.");

            return self::SUCCESS;
        }

        if (! $this->option('sync')) {
            $contas->each(fn (InstagramAccount $c) => RefreshInstagramTokenJob::dispatch($c->id));
            $this->info("{$contas->count()} renovação(ões) enviada(s) para a fila ".config('postflow.queues.publish').'.');

            return self::SUCCESS;
        }

        $falhas = 0;
        foreach ($contas as $conta) {
            $antes = $conta->token_expires_at;

            try {
                RefreshInstagramTokenJob::dispatchSync($conta->id);
            } catch (Throwable $e) {
                $falhas++;
                $this->error("@{$conta->username} (#{$conta->id}): {$e->getMessage()}");

                continue;
            }

            $conta->refresh();
            if ($conta->status !== AccountStatus::Ativo) {
                $falhas++;
                $this->error("@{$conta->username} (#{$conta->id}): marcada como '{$conta->status->value}'.");

                continue;
            }

            $this->info("@{$conta->username} (#{$conta->id}): vencia em {$antes?->toDateTimeString()}, agora vence em {$conta->token_expires_at->toDateTimeString()}.");
        }

        return $falhas ? self::FAILURE : self::SUCCESS;
    }
}
