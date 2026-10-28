<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeliOrder extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('meli_buyer', 'articles.images', 'tags', 'cancel_detail', 'sale', 'meli_order_status');
    }

    /**
     * Columnas que el listado MUESTRA distinto de como están guardadas (ver "COLUMNAS MOSTRADAS"
     * en ColumnFiltersHelper): la lupa y el orden de "Estado" van por lo que se ve.
     *
     * Misión mercado-libre-pruebas (27/10/2026): la SPA muestra el estado de Mercado Libre en
     * castellano (`meli_order_estado_texto` de src/mixins/model_functions.js) y filtrar por "pagado"
     * no encontraba nada porque se buscaba sobre `paid`. Se cambia junto con esa función.
     *
     * @return array<string, string>  columna => expresión SQL de lo que la tabla muestra.
     */
    public static function columnas_mostradas_para_filtros()
    {
        $estado = "CASE meli_orders.status"
            . " WHEN 'confirmed' THEN 'Confirmado, sin pagar'"
            . " WHEN 'payment_required' THEN 'Esperando el pago'"
            . " WHEN 'payment_in_process' THEN 'Pago en proceso'"
            . " WHEN 'partially_paid' THEN 'Pagado en parte'"
            . " WHEN 'paid' THEN 'Pagado'"
            . " WHEN 'partially_refunded' THEN 'Devuelto en parte'"
            . " WHEN 'pending_cancel' THEN 'Cancelándose'"
            . " WHEN 'cancelled' THEN 'Cancelado'"
            . " WHEN 'invalid' THEN 'Inválido'"
            . " ELSE meli_orders.status END";

        return [
            'status' => 'CONVERT((' . $estado . ') USING utf8mb4) COLLATE utf8mb4_unicode_ci',
        ];
    }


    public function sale()
    {
        return $this->hasOne(Sale::class);
    }

    /**
     * Estado interno de gestión (pendiente / confirmado → venta).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function meli_order_status()
    {
        return $this->belongsTo(MeliOrderStatus::class);
    }

    public function meli_buyer()
    {
        return $this->belongsTo(MeliBuyer::class);
    }

    public function articles()
    {
        return $this->belongsToMany(Article::class, 'meli_order_article')
                    ->withPivot(['amount', 'price'])
                    ->withTimestamps();
    }

    public function tags()
    {
        return $this->hasMany(MeliOrderTag::class, 'meli_order_id');
    }

    public function cancel_detail()
    {
        return $this->hasOne(MeliOrderCancelDetail::class, 'meli_order_id');
    }
}
