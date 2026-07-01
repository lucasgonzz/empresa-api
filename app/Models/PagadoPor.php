<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de imputación de pago: vincula un débito (venta/comisión) con el
 * haber (pago) que lo cubre total o parcialmente. Tabla pivot de current_acounts.
 */
class PagadoPor extends Model
{
    protected $table = 'pagado_por';

    protected $fillable = [
        'debe_id', 'haber_id', 'pagado', 'total_pago',
        'a_cubrir', 'fondos_iniciales', 'nuevos_fondos', 'remantente',
    ];

    // Movimiento de deuda (débito) que esta imputación cubre.
    public function debe()
    {
        return $this->belongsTo(CurrentAcount::class, 'debe_id');
    }

    // Movimiento de pago (haber) que realiza la cobertura.
    public function haber()
    {
        return $this->belongsTo(CurrentAcount::class, 'haber_id');
    }
}
