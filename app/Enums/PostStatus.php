<?php

namespace App\Enums;

enum PostStatus: string
{
    case Agendado = 'agendado';
    case Gerando = 'gerando';
    case AguardandoAprovacao = 'aguardando_aprovacao';
    case Aprovado = 'aprovado';
    case Publicando = 'publicando';
    case Publicado = 'publicado';
    case Rejeitado = 'rejeitado';
    case Expirado = 'expirado';
    case Erro = 'erro';

    public function isFinal(): bool
    {
        return in_array($this, [self::Publicado, self::Rejeitado, self::Expirado, self::Erro], true);
    }
}
