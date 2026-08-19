<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Consumo de tokens de una llamada a la API de Anthropic.
 *
 * Una fila por llamada HTTP (en el loop de tool use del chat, cada iteración
 * graba la suya: cada respuesta trae su propio bloque `usage`). Se escribe
 * siempre vía AiTokenUsageHelper::registrar(), que nunca lanza: una falla de
 * metering no puede voltear una respuesta que el usuario ya está esperando.
 *
 * Sin UI en esta misión: la tabla junta números para decidir después.
 */
class AiTokenUsage extends Model
{
    protected $guarded = [];
}
