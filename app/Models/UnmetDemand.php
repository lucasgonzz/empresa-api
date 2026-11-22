<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un freno de Vender por falta de stock: alguien quiso vender un artículo y el sistema no lo
 * dejó agregar porque no había (motivo `sin_stock`) o no alcanzaba (`stock_insuficiente`).
 * Es la "demanda no satisfecha" que leen el motor de sugerencias de compra y el asistente de IA.
 *
 * Una fila = un freno. `canceled_at` lo marca el vendedor con "Cancelar" (fue un error): la fila
 * no se borra, pero deja de contar. Ver UnmetDemandHelper para la lógica.
 */
class UnmetDemand extends Model
{
    protected $guarded = [];

    /**
     * Tipos de las columnas: decimales como float y fecha de cancelación como Carbon.
     *
     * @var array
     */
    protected $casts = [
        'cantidad_pedida'  => 'float',
        'stock_disponible' => 'float',
        'canceled_at'      => 'datetime',
    ];

    /**
     * Sin relaciones que precargar: el modelo se devuelve plano a la SPA.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query) {}
}
