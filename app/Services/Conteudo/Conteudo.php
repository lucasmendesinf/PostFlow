<?php

namespace App\Services\Conteudo;

/** Roteiro de um post devolvido pela IA, ja validado. */
final class Conteudo
{
    /**
     * @param  array<int, string>  $hashtags
     * @param  array<int, array{html: string, css: string}>  $slides
     * @param  array{modelo?: string, entrada?: int, saida?: int, cache_leitura?: int, cache_escrita?: int}  $uso
     */
    public function __construct(
        public readonly string $tema,
        public readonly string $legenda,
        public readonly array $hashtags,
        public readonly string $altText,
        public readonly array $slides,
        public readonly array $uso = [],
    ) {}

    /** Legenda final do Instagram: texto + hashtags (sem repetir as que ja estao no texto). */
    public function legendaCompleta(): string
    {
        $faltam = array_filter($this->hashtags, fn ($h) => ! str_contains(mb_strtolower($this->legenda), mb_strtolower($h)));

        return trim($this->legenda.($faltam ? "\n\n".implode(' ', $faltam) : ''));
    }

    public function paraArray(): array
    {
        return ['tema' => $this->tema, 'legenda' => $this->legenda, 'hashtags' => $this->hashtags, 'alt_text' => $this->altText, 'slides' => $this->slides, 'uso' => $this->uso];
    }

    public static function deArray(array $d): self
    {
        return new self((string) ($d['tema'] ?? ''), (string) ($d['legenda'] ?? ''), array_values($d['hashtags'] ?? []), (string) ($d['alt_text'] ?? ''), array_values($d['slides'] ?? []), $d['uso'] ?? []);
    }
}
