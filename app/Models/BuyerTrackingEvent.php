<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Evento crudo de comportamiento de un comprador en la tienda
 * (misión tracking-buyers-tienda).
 *
 * Este modelo existe sobre todo como fuente única de la verdad del CONTRATO con
 * tienda-api: la constante TIPOS de abajo es la lista blanca que la ingesta de la
 * tienda espeja, y es lo que evita que las dos puntas terminen validando el mismo
 * invariante con dos criterios distintos.
 *
 * El camino de escritura NO pasa por acá: tienda-api inserta por query builder,
 * a propósito, para no hidratar un objeto ni disparar eventos de modelo por cada
 * vista de producto de cada visitante. El modelo es para el ERP, que va a leer
 * (en la misión de las pantallas y el motor de ofertas) y para los comandos.
 */
class BuyerTrackingEvent extends Model
{
    /**
     * Lista blanca de tipos de evento. Es EL contrato con tienda-api: el helper
     * de ingesta de la tienda valida contra esta misma lista y descarta lo que no
     * esté acá.
     *
     * Agregar un tipo es aditivo y seguro (la tienda vieja simplemente no lo
     * manda). Renombrar o sacar uno NO lo es: los dos proyectos nunca llegan a
     * producción a la vez, así que durante la ventana entre los dos despliegues
     * la tienda seguiría mandando el nombre viejo y esos eventos se perderían en
     * silencio.
     *
     * @var array<int, string>
     */
    const TIPOS = [
        'product_view',      // vista de producto (trae dwell_ms)
        'search',            // búsqueda (trae search_term + results_count)
        'cart_add',          // agregado al carrito
        'cart_remove',       // quitado del carrito
        'checkout_start',    // entró a confirmar compra
        'checkout_complete', // pedido creado (trae order_id + amount)
    ];

    /**
     * Sin restricción de asignación masiva: los puntos de escritura son la
     * ingesta de tienda-api (que arma la lista blanca de columnas a mano) y los
     * comandos de esta misión.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'occurred_at'     => 'datetime',
        'user_id'         => 'integer',
        'buyer_id'        => 'integer',
        'article_id'      => 'integer',
        'category_id'     => 'integer',
        'sub_category_id' => 'integer',
        'order_id'        => 'integer',
        'results_count'   => 'integer',
        'quantity'        => 'integer',
        'dwell_ms'        => 'integer',
    ];

    /**
     * Scope requerido por Controller::fullModel(). No tiene relaciones que cargar:
     * las FK son lógicas (sin foreign keys físicas) y el ERP consulta agregados,
     * no eventos hidratados de a uno.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query;
    }
}
