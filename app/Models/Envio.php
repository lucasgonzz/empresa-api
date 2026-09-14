<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Envío real generado en un proveedor de logística (hoy Zipnova) a partir de un pedido de la
 * tienda (misión zipnova-envios, 14/9/2026). Tabla `envios`.
 *
 * Es distinto de `orders.envio_opcion` a propósito: la opción es lo que el comprador ELIGIÓ al
 * comprar (correo, servicio, precio); el envío es lo que el comercio GENERÓ después, con id en
 * Zipnova, número de guía, etiqueta y un estado que va cambiando hasta "entregado". Un pedido
 * puede tener la opción y ningún envío todavía (no se confirmó, o Zipnova falló), y un envío
 * cancelado puede reemplazarse por otro del mismo pedido: por eso `order_id` no es único y
 * `Order::envio()` toma el más nuevo.
 *
 * `status` / `status_name` guardan tal cual el código y el nombre que manda Zipnova
 * (docs.zipnova.com/envios/referencia/estados-de-envio), sin traducir a mano: Zipnova ya los
 * manda en castellano y agrega estados sin avisar. El único valor propio es `STATUS_ERROR`,
 * "nunca se pudo crear", con el motivo en `error_message`; esa fila se reutiliza en el reintento.
 *
 * `respuesta` es el último payload completo de Zipnova, para no perder datos que la tabla no
 * modela. `tienda-api` tiene su propio modelo espejo de solo lectura (`App\Envio`) que lo oculta.
 */
class Envio extends Model
{
    /** Proveedor con el que se generó el envío. Hoy el único. */
    const PROVEEDOR_ZIPNOVA = 'zipnova';

    /**
     * Estado propio de ComercioCity: el envío nunca llegó a existir en Zipnova (el `POST
     * /shipments` falló). No es un estado de Zipnova. La fila queda para mostrar el motivo y para
     * que el reintento la actualice en vez de crear otra.
     */
    const STATUS_ERROR = 'error';

    /**
     * Estados de Zipnova desde los que el envío ya no se mueve. Sirven para que el comando de
     * sincronización deje de consultar un envío terminado y para saber si se puede cancelar.
     *
     * @var array<int, string>
     */
    const ESTADOS_FINALES = [
        'cancelled',
        'expired',
        'reshipped',
        'delivered',
        'delivered_with_damage',
        'lost_in_carrier',
        'generated_return',
        'returned_to_seller',
        'lost',
    ];

    /**
     * Estados en los que el envío NO cuenta como "vivo" para el pedido: se puede generar uno
     * nuevo. `error` porque nunca existió; `cancelled` y `expired` porque Zipnova ya lo cerró
     * sin que llegara a viajar.
     *
     * @var array<int, string>
     */
    const ESTADOS_REEMPLAZABLES = ['error', 'cancelled', 'expired'];

    protected $table = 'envios';

    protected $guarded = [];

    /**
     * Los tres json y las dos fechas. `estimated_delivery` viene de Zipnova en ISO 8601 con zona
     * y se guarda como timestamp; `ultima_sincronizacion` la escribe el servicio en cada
     * `GET /shipments/{id}`.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'destino'               => 'array',
        'bultos'                => 'array',
        'respuesta'             => 'array',
        'estimated_delivery'    => 'datetime',
        'ultima_sincronizacion' => 'datetime',
    ];

    /**
     * Scope estándar del proyecto para `fullModel` / listados. El pedido se carga desde el otro
     * lado (`Order::withAll` trae `envio`); acá no hace falta nada.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query;
    }

    /**
     * Pedido de la tienda del que salió el envío.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Comercio dueño del envío.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * True si Zipnova ya cerró el envío (entregado, cancelado, perdido, devuelto...): no hay
     * nada más que sincronizar ni se puede cancelar.
     *
     * @return bool
     */
    public function esta_cerrado(): bool
    {
        return in_array((string) $this->status, self::ESTADOS_FINALES, true);
    }

    /**
     * True si el envío existe en Zipnova y todavía cuenta para el pedido: tiene id del proveedor
     * y no está en un estado reemplazable. Con uno vivo no se genera otro.
     *
     * @return bool
     */
    public function esta_vivo(): bool
    {
        if (empty($this->proveedor_envio_id)) {
            return false;
        }

        return !in_array((string) $this->status, self::ESTADOS_REEMPLAZABLES, true);
    }

    /**
     * True si se puede pedir la cancelación a Zipnova: existe allá y no está cerrado.
     *
     * @return bool
     */
    public function se_puede_cancelar(): bool
    {
        return $this->esta_vivo() && !$this->esta_cerrado();
    }
}
