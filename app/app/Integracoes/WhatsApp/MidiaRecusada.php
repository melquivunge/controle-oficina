<?php

namespace App\Integracoes\WhatsApp;

use RuntimeException;

/** Mídia recusada por validação (tamanho/tipo): não é falha transitória, não se tenta de novo. */
class MidiaRecusada extends RuntimeException {}
