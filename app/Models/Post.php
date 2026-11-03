<?php

namespace App\Models;

use App\Enums\PostFormat;
use App\Enums\PostStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Post extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'account_id', 'schedule_id', 'idea_id', 'formato', 'status', 'versao_aprovada_id',
        'agendado_para', 'ig_container_id', 'ig_media_id', 'publicado_em', 'erro',
        'tentativas', 'proxima_tentativa_em', 'comentario_pendente',
    ];

    protected $attributes = [
        'status' => 'agendado',
    ];

    protected function casts(): array
    {
        return [
            'formato' => PostFormat::class,
            'status' => PostStatus::class,
            'agendado_para' => 'datetime',
            'publicado_em' => 'datetime',
            'proxima_tentativa_em' => 'datetime',
            'tentativas' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(InstagramAccount::class, 'account_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function idea(): BelongsTo
    {
        return $this->belongsTo(Idea::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PostVersion::class)->orderBy('versao');
    }

    public function versaoAprovada(): BelongsTo
    {
        return $this->belongsTo(PostVersion::class, 'versao_aprovada_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PostEvent::class)->orderBy('id');
    }

    /**
     * Muda o status e registra a mudança em post_events, na mesma transação.
     * Os workers chamam isto a cada etapa (alimenta o painel e a auditoria).
     */
    public function transitionTo(PostStatus $novo, ?string $detalhe = null): void
    {
        DB::transaction(function () use ($novo, $detalhe) {
            $anterior = $this->status;

            $this->status = $novo;
            if ($novo === PostStatus::Erro) {
                $this->erro = $detalhe;
            }
            $this->save();

            $this->events()->create([
                'status_anterior' => $anterior?->value,
                'status_novo' => $novo->value,
                'detalhe' => $detalhe,
            ]);
        });
    }
}
