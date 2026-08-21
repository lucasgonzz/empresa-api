<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un renglón del libro de movimientos de puntos.
 *
 * 🔴 `puntos` es SIGNADO y el saldo del cliente es SUM(puntos) sobre esta tabla, sin
 * ninguna otra condición. Los movimientos que restan ('canjeados', 'vencidos',
 * 'revertidos') ya vienen con el número en negativo, así que no hay dos criterios posibles
 * para calcular el mismo saldo. Ver el docblock de la migración.
 *
 * Laravel pluraliza `MovimientoPunto` a 'movimiento_puntos' solo, igual que
 * MovimientoCaja -> movimiento_cajas: acá no hace falta declarar $table.
 */
class MovimientoPunto extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('client', 'sale', 'price_type');
    }

    function client() {
        return $this->belongsTo(Client::class);
    }

    function sale() {
        return $this->belongsTo(Sale::class);
    }

    function price_type() {
        return $this->belongsTo(PriceType::class);
    }

    function sistema_de_puntos() {
        return $this->belongsTo(SistemaDePuntos::class);
    }

    function employee() {
        return $this->belongsTo(User::class, 'employee_id');
    }

    /**
     * Si ESTE movimiento es un consumo ('canjeados' o 'vencidos'): de qué lotes se llevó los
     * puntos. Es lo que se lee para deshacer un canje.
     */
    function consumos() {
        return $this->hasMany(MovimientoPuntoConsumo::class, 'movimiento_consumo_id');
    }

    /**
     * Si ESTE movimiento es un lote ('ganados'): qué consumos se lo comieron. El remanente
     * vivo del lote es `puntos - consumido`, que es una columna y no una suma de acá: esta
     * relación es para auditar, no para calcular.
     */
    function consumido_de() {
        return $this->hasMany(MovimientoPuntoConsumo::class, 'movimiento_origen_id');
    }
}
