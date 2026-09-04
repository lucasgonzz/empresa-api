<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un renglón del libro de movimientos de puntos.
 *
 * 🔴 `puntos` es SIGNADO: los movimientos que restan ('canjeados', 'vencidos',
 * 'revertidos') ya vienen con el número en negativo.
 *
 * 🔴 Pero OJO: SUM(puntos) sobre esta tabla NO es lo que el cliente puede gastar hoy. Son
 * dos preguntas distintas y cada una tiene su método en PuntosSaldoHelper:
 *
 *   - saldo_del_libro()  = SUM(puntos) crudo. Es el PASIVO del programa, lo que se debe.
 *   - saldo()            = el DISPONIBLE. Le descuenta los lotes ya vencidos que el barrido
 *                          diario todavía no marcó. Es lo que se valida al canjear y lo que
 *                          se muestra en VENDER y en la ficha del cliente.
 *
 * Antes de que existiera saldo(), un cliente podía canjear puntos vencidos-todavía-no-
 * barridos: el canje pasaba la validación pero no consumía ningún lote, y después
 * `puntos:vencer` los vencía igual. El mismo punto se descontaba dos veces y el saldo
 * quedaba negativo. Si volvés a colapsar las dos preguntas en una, vuelve ese bug.
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
