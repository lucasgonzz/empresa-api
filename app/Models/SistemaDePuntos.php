<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La configuración del programa de puntos de un comercio.
 *
 * El invariante "un solo programa activo por user_id" NO está en la base (no hay unique en
 * `user_id`, ver la migración): lo sostiene SistemaDePuntosController al guardar. La tabla
 * es plural a propósito para poder tener varios el día que se quieran.
 */
class SistemaDePuntos extends Model
{
    protected $guarded = [];

    /*
     * 🔴 OBLIGATORIO. Laravel derivaria 'sistema_de_puntos' para esta clase, y la tabla es
     * PLURAL desde el dia uno (decision de Lucas, 21/8/2026: hedge para poder tener varios
     * programas sin un backfill sobre las bases de los clientes).
     */
    protected $table = 'sistemas_de_puntos';

    function scopeWithAll($q) {
        $q->with('price_types');
    }

    function price_types() {
        /*
         * 🔴 Los tres argumentos van a mano: el default de Laravel seria la tabla
         * 'price_type_sistema_de_punto' (singular) y la tabla se llama en plural.
         */
        return $this->belongsToMany(
            PriceType::class,
            'price_type_sistema_de_puntos',
            'sistema_de_puntos_id',
            'price_type_id'
        )->withPivot('multiplicador');
    }
}
