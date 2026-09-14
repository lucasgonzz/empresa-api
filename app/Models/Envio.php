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
     * /shipments` falló, o una precondición no se cumplió al confirmar el pedido). No es un
     * estado de Zipnova. La fila queda para mostrar el motivo y para que el reintento la
     * actualice en vez de crear otra.
     */
    const STATUS_ERROR = 'error';

    /**
     * Estado propio: la fila se escribió y el request está EN ESTE MOMENTO esperando a Zipnova.
     * Es el candado contra el doble click en "Generar envío" y contra el modal + la confirmación
     * automática pisándose: la fila se graba (y se commitea) antes de salir a Zipnova, y
     * cualquier otro request que llegue mientras tanto la ve como un envío vivo. Si el proceso
     * murió a mitad de camino, a los `MINUTOS_GENERANDO` se considera abandonada y reemplazable.
     */
    const STATUS_GENERANDO = 'generando';

    /**
     * Estado propio: Zipnova respondió 404 al consultar un envío que sí tenía id (lo borraron
     * del panel, o el comercio reconectó con otra cuenta que no lo ve). Para ComercioCity ya no
     * es un envío vivo: se puede generar otro, no se puede cancelar y el comando de
     * sincronización deja de consultarlo.
     */
    const STATUS_NO_ENCONTRADO = 'not_found';

    /** Minutos que una fila puede quedar en `generando` antes de considerarse abandonada. */
    const MINUTOS_GENERANDO = 2;

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
     * nuevo. `error` porque nunca existió; `not_found` porque Zipnova ya no lo tiene;
     * `cancelled` y `expired` porque Zipnova lo cerró sin que llegara a viajar. `generando` no
     * está acá: es reemplazable solo cuando quedó abandonado (ver `generando_vigente()`).
     *
     * @var array<int, string>
     */
    const ESTADOS_REEMPLAZABLES = ['error', 'not_found', 'cancelled', 'expired'];

    /**
     * Estados propios en los que no hay nada que consultarle a Zipnova: el comando de
     * sincronización los excluye junto con los finales.
     *
     * @var array<int, string>
     */
    const ESTADOS_SIN_SEGUIMIENTO = ['error', 'not_found', 'generando'];

    protected $table = 'envios';

    protected $guarded = [];

    /**
     * No viajan al SPA: `respuesta` es el payload entero de Zipnova y `bultos` la lista de
     * ítems que se mandó, 2-3 KB por pedido que el listado y el modal no usan. Siguen en la
     * base para diagnosticar (`$envio->respuesta` desde código o tinker los lee igual).
     *
     * @var array<int, string>
     */
    protected $hidden = ['respuesta', 'bultos'];

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
     * True si el envío ya no se mueve: Zipnova lo cerró (entregado, cancelado, perdido,
     * devuelto...) o ya no lo encuentra. No hay nada más que sincronizar ni se puede cancelar.
     *
     * @return bool
     */
    public function esta_cerrado(): bool
    {
        if ((string) $this->status === self::STATUS_NO_ENCONTRADO) {
            return true;
        }

        return in_array((string) $this->status, self::ESTADOS_FINALES, true);
    }

    /**
     * True si la fila está en `generando` y todavía dentro de la ventana: hay un request que
     * salió a Zipnova hace menos de `MINUTOS_GENERANDO` y no volvió. Pasada la ventana, el
     * proceso murió y la fila se puede reutilizar.
     *
     * @return bool
     */
    public function generando_vigente(): bool
    {
        if ((string) $this->status !== self::STATUS_GENERANDO) {
            return false;
        }

        $desde = $this->updated_at ? $this->updated_at : $this->created_at;

        return !is_null($desde) && $desde->gt(now()->subMinutes(self::MINUTOS_GENERANDO));
    }

    /**
     * True si el envío todavía cuenta para el pedido: se está generando ahora mismo, o existe
     * en Zipnova y no está en un estado reemplazable. Con uno vivo no se genera otro.
     *
     * @return bool
     */
    public function esta_vivo(): bool
    {
        if ((string) $this->status === self::STATUS_GENERANDO) {
            return $this->generando_vigente();
        }

        if (empty($this->proveedor_envio_id)) {
            return false;
        }

        return !in_array((string) $this->status, self::ESTADOS_REEMPLAZABLES, true);
    }

    /**
     * True si se puede pedir la cancelación a Zipnova: existe allá (tiene id) y no está
     * cerrado. Una fila en `generando` todavía no tiene id: no hay qué cancelar.
     *
     * @return bool
     */
    public function se_puede_cancelar(): bool
    {
        return !empty($this->proveedor_envio_id) && $this->esta_vivo() && !$this->esta_cerrado();
    }
}
