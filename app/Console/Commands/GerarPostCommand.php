<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\Pipeline\Ciclo;
use Illuminate\Console\Command;

class GerarPostCommand extends Command
{
    protected $signature = 'postflow:gerar {post : ID do post} {--comentario= : ajuste pedido para a nova versão}';

    protected $description = 'Gera (ou refaz) a arte e a legenda de um post agora, sem esperar o ciclo';

    public function handle(Ciclo $ciclo): int
    {
        $post = Post::findOrFail($this->argument('post'));
        if ($this->option('comentario')) {
            $post->forceFill(['comentario_pendente' => $this->option('comentario')])->save();
        }
        $v = $ciclo->gerarUm($post);
        $this->info("Versão {$v->versao} gerada:");
        foreach ((array) $v->media_urls as $u) $this->line("  {$u}");
        $this->line('');
        $this->line($v->legenda);

        return self::SUCCESS;
    }
}
