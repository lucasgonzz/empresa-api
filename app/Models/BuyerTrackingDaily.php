<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila del agregado diario de comportamiento de compradores
 * (misión tracking-buyers-tienda).
 *
 * Existe porque los eventos crudos se purgan a los 90 días y esto no: es la única
 * memoria de largo plazo del comportamiento de la tienda. Una fila es un
 * (comercio, día, comprador, artículo, tipo de evento, término buscado) con sus
 * totales: cantidad de eventos, visitantes distintos, tiempo en pantalla, monto y
 * —para las búsquedas— el máximo de resultados que devolvió.
 *
 * Que el término buscado y los resultados estén acá y no resumidos es lo que
 * permite contestar "qué buscaron y no encontraron" después de que la purga se
 * llevó los eventos crudos; pasada esa ventana, lo que no esté en esta tabla no
 * se puede reconstruir con nada.
 *
 * La escribe `tracking:agregar-buyers`, que borra el día completo y lo reinserta.
 * Nada más debería escribirle: si dos fuentes le insertan, el borrado del rollup
 * se lleva puesto lo de la otra.
 */
class BuyerTrackingDaily extends Model
{
    /**
     * Nombre explícito: la tabla es de agregado diario y se llama en singular a
     * propósito. Sin esto Eloquent inferiría `buyer_tracking_dailies` (mismo caso
     * que DemoTrackingConfig).
     *
     * @var string
     */
    protected $table = 'buyer_tracking_daily';

    /**
     * Sin restricción de asignación masiva: el único punto de escritura es el
     * comando de rollup, que arma el array completo a mano.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'fecha'          => 'date',
        'user_id'        => 'integer',
        'buyer_id'       => 'integer',
        'article_id'     => 'integer',
        'total'          => 'integer',
        'visitantes'     => 'integer',
        'dwell_ms_total' => 'integer',
        /* El cast no pisa el NULL (Eloquent lo devuelve tal cual): 0 = no encontró nada, null = no aplica */
        'results_count'  => 'integer',
    ];

    /**
     * Scope requerido por Controller::fullModel(). No tiene relaciones que cargar.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query;
    }
}
