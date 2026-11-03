<?php

namespace App\Services\Instagram;

/** A conta chegou ao limite de publicacoes das ultimas 24 h: tentar de novo mais tarde. */
class LimitePublicacaoAtingido extends InstagramApiException {}
