<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Snapshot diario del saldo de una credit_account (entidad cliente/proveedor × moneda).
 * Lo escribe `debt:snapshot` (TakeDebtSnapshot) todas las noches, junto con los totales
 * agregados de debt_snapshots.
 *
 * REGLA DE LECTURA — la contracara obligatoria de "guardar solo cambios":
 * se escribe una fila SOLO el día en que el saldo cambió respecto del último snapshot de
 * esa cuenta (o el primer día en que la cuenta tuvo saldo distinto de cero). Por lo tanto:
 *
 *   - El saldo de la cuenta al día `d` es el del ÚLTIMO snapshot con `date <= d`.
 *   - Si no existe ningún snapshot con `date <= d`, el saldo a ese día es 0.
 *   - La ausencia de fila en un día NO significa saldo cero: significa "igual que el último".
 *
 * Esa regla vive acá (scopeSaldoAlDia) y en ningún otro lado: cualquier consumidor del
 * historial (la misión B de sugerencias de compra, un futuro endpoint de lectura) tiene que
 * pasar por este scope para no reinventarla distinto.
 *
 * model_name / model_id / moneda_id vienen denormalizados de credit_accounts para poder
 * consultar el historial de una entidad sin join (índice model_name+model_id+moneda_id+date).
 */
class CreditAccountSnapshot extends Model
{
    /**
     * Sin restricciones en asignación masiva; todos los campos son asignables.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * Scope vacío requerido por Controller::fullModel() para invocar ->withAll() sin errores.
     * Actualmente no carga relaciones adicionales.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $q
     * @return \Illuminate\Database\Eloquent\Builder
     */
    function scopeWithAll($q)
    {
        return $q;
    }

    /**
     * Acota la consulta a los snapshots vigentes al día `$date` (date <= $date), ordenados
     * del más nuevo al más viejo: encadenado a un filtro por cuenta, `->first()` devuelve el
     * snapshot que fija el saldo de esa cuenta a ese día, o null si la cuenta no tenía
     * historia todavía (y entonces su saldo a ese día es 0 — ver la regla de lectura en el
     * docblock de la clase).
     *
     * Ejemplo:
     *   CreditAccountSnapshot::where('credit_account_id', $id)->saldoAlDia('2026-08-14')->first();
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $q
     * @param  string  $date  Fecha tope, formato Y-m-d.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    function scopeSaldoAlDia($q, $date)
    {
        return $q->where('date', '<=', $date)
                 ->orderBy('date', 'desc');
    }
}
