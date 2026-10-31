<?php

namespace App\Enums;

enum AccountStatus: string
{
    case Ativo = 'ativo';
    case Reconectar = 'reconectar';
    case Desconectado = 'desconectado';
}
