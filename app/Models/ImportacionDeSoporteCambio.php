<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Lo que hizo una ImportacionDeSoporte, fila por fila, para poder deshacerlo (misión importaciones-de-martin,
 * 11/11/2026): un cliente o proveedor creado, uno actualizado (con `antes` y `despues` de los campos que
 * cambiaron) o un movimiento de cuenta corriente generado por el saldo del Excel.
 */
class ImportacionDeSoporteCambio extends Model
{
    protected $table = 'importacion_de_soporte_cambios';

    protected $guarded = [];

    protected $casts = [
        'antes' => 'array',
        'despues' => 'array',
    ];

    const TIPO_CREADO = 'creado';
    const TIPO_ACTUALIZADO = 'actualizado';
    const TIPO_MOVIMIENTO = 'movimiento';

    public function scopeWithAll($query)
    {
        return $query;
    }
}
