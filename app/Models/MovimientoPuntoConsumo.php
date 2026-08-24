<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Qué lote de puntos consumió un canje o un vencimiento, y por cuánto.
 *
 * Es el calco de `pagado_por` de la cuenta corriente de plata. Ver el docblock de la
 * migración 2026_08_22_100400 por qué existe esta tabla en vez de calcular el FIFO al vuelo.
 */
class MovimientoPuntoConsumo extends Model
{
    protected $guarded = [];

    /**
     * El lote 'ganados' del que salieron los puntos.
     */
    function movimiento_origen() {
        return $this->belongsTo(MovimientoPunto::class, 'movimiento_origen_id');
    }

    /**
     * El movimiento 'canjeados' o 'vencidos' que se los llevó.
     */
    function movimiento_consumo() {
        return $this->belongsTo(MovimientoPunto::class, 'movimiento_consumo_id');
    }
}
