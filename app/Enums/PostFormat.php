<?php

namespace App\Enums;

enum PostFormat: string
{
    case Feed = 'feed';
    case Carrossel = 'carrossel';
    case Stories = 'stories';

    /** @return array{0: int, 1: int} largura e altura em pixels */
    public function dimensions(): array
    {
        return match ($this) {
            self::Feed, self::Carrossel => [1080, 1350],
            self::Stories => [1080, 1920],
        };
    }
}
