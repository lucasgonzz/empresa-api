<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un escaneo de la factura (o del remito) que el proveedor trajo con la mercadería.
 *
 * El usuario saca una o varias fotos, las manda todas juntas en una sola subida, y un job en
 * segundo plano las lee con IA y deja el JSON de lo que encontró en `resultado`. Después el
 * usuario abre esa lectura, la corrige a mano y la confirma; recién ahí se asienta en la
 * compra. Nada de lo que hay acá adentro toca la compra por su cuenta.
 *
 * Estados (`estado`): pendiente | procesando | listo | error. Los mismos cuatro que
 * ExcelAnalysisRun.
 *
 * Cómo termina (`resultado_gestion`): confirmado | descartado, o null mientras el usuario
 * todavía no lo resolvió.
 *
 * 🔴 `resultado` guarda DOS cosas en momentos distintos, y no es un descuido: primero lo que
 * leyó la IA, y después de confirmar, lo que el usuario dejó. El registro tiene que decir qué
 * se asentó, no qué se leyó. Lo que se asentó de verdad (contadores) va en `aplicado`.
 */
class ProviderOrderScan extends Model
{
    /* Sin restricciones de asignación masiva: se inserta/actualiza vía array. */
    protected $guarded = [];

    /**
     * Estado con el que un escaneo terminó bien y ya tiene resultado para revisar.
     *
     * Es la mitad del criterio del botón rojo, y está en una constante justamente para que el
     * scope (SQL) y el método de instancia (memoria) no puedan escribir el literal distinto.
     */
    const ESTADO_LISTO = 'listo';

    /*
     * `resultado` y `aplicado` se guardan como JSON y se leen como arrays PHP.
     * `visto_at` y `gestionado_at` como Carbon, para poder compararlos y serializarlos sin
     * parsear a mano.
     */
    protected $casts = [
        'resultado'     => 'array',
        'aplicado'      => 'array',
        'visto_at'      => 'datetime',
        'gestionado_at' => 'datetime',
    ];

    /**
     * Scope requerido por Controller::fullModel(): trae el escaneo con sus fotos.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    function scopeWithAll($query)
    {
        $query->with('images');

        return $query;
    }

    /**
     * Las fotos del escaneo, SIEMPRE ordenadas por página.
     *
     * El orden no es cosmético: es el que se le manda a la IA como "página 1 de 3" y el que usa
     * el modal de revisión para mostrar las fotos al lado de la tabla. Va en la relación y no
     * en cada llamador para que no haya un solo lugar donde se pueda olvidar.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    function images()
    {
        return $this->hasMany(ProviderOrderScanImage::class)->orderBy('orden');
    }

    /**
     * La compra a la que pertenece el escaneo.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    function provider_order()
    {
        return $this->belongsTo(ProviderOrder::class);
    }

    /**
     * 🔴 LA definición de "escaneo pendiente de revisar" — versión SQL: lo que enciende el
     * botón rojo "Revisar escaneo" en el listado de compras.
     *
     * `estado = 'listo'` Y `gestionado_at IS NULL`. No es "no visto" (mirar el aviso no asienta
     * nada) y no es "sin confirmar" (un escaneo descartado tampoco tiene que encender el
     * botón): es que la lectura haya terminado bien Y que el usuario todavía no la haya
     * resuelto ni por confirmar ni por descartar.
     *
     * 🔴 El criterio vive en ESTE PAR de métodos hermanos y en ningún otro lado: este scope es
     * la condición escrita en SQL (la usa ProviderOrderScanController::pendientes()) y
     * esta_pendiente_de_revisar(), acá abajo, es la MISMA condición evaluada en memoria sobre
     * una fila ya cargada. Están pegados a propósito y comparten self::ESTADO_LISTO: si alguna
     * vez hay que cambiar el criterio, se cambian los dos juntos, se ven de una sola mirada, y
     * ningún controlador tiene que reimplementarlo inline.
     *
     * Se apoya en el índice `pos_pendientes_idx` (user_id, estado, gestionado_at), así que el
     * filtro por owner va afuera, en el llamador, y este scope solo agrega las dos condiciones
     * del criterio.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    function scopePendientesDeRevisar($query)
    {
        return $query->where('estado', self::ESTADO_LISTO)
                        ->whereNull('gestionado_at');
    }

    /**
     * 🔴 EL MISMO criterio del scope de arriba, evaluado en memoria sobre una fila ya cargada.
     *
     * Lo usa ProviderOrderScanController::show(), que ya tiene el escaneo en la mano y no va a
     * volver a la base solo para preguntar algo que puede responder con dos campos.
     *
     * Si tocás este método, tocá scopePendientesDeRevisar() en el mismo commit: son la misma
     * condición y tienen que dar siempre lo mismo (lo blinda
     * 1_Esquema_y_extencion_Test::el_scope_y_el_metodo_dicen_lo_mismo).
     *
     * @return bool
     */
    function esta_pendiente_de_revisar()
    {
        return $this->estado === self::ESTADO_LISTO && is_null($this->gestionado_at);
    }

    /**
     * Lo mínimo que el frontend necesita para hablar de este escaneo sin abrirlo: de qué compra
     * es, cuántas páginas tiene, cuántos artículos se leyeron y a qué proveedor le compramos.
     *
     * Es lo que viaja en el aviso de "terminó el escaneo". A propósito NO incluye `resultado`:
     * el aviso solo dice que terminó, y el resumen se pide recién si el usuario aprieta el
     * botón. Mandar el JSON entero por el broadcast sería empujar decenas de KB a todas las
     * pestañas conectadas para que la mayoría los tire.
     *
     * @return array
     */
    function contexto_para_frontend()
    {
        $resultado = $this->resultado;

        if (!is_array($resultado)) {
            $resultado = [];
        }

        $articulos = [];

        if (isset($resultado['articulos']) && is_array($resultado['articulos'])) {
            $articulos = $resultado['articulos'];
        }

        /*
         * Si la relación ya viene cargada (scopeWithAll) se cuenta en memoria; si no, un count()
         * por SQL. Sin esta guarda, cada llamada a este método hace una consulta de más aunque
         * el escaneo se haya traído con sus fotos.
         */
        if ($this->relationLoaded('images')) {
            $cantidad_imagenes = $this->images->count();
        } else {
            $cantidad_imagenes = $this->images()->count();
        }

        return [
            'provider_order_id'  => $this->provider_order_id,
            'cantidad_imagenes'  => $cantidad_imagenes,
            'cantidad_articulos' => count($articulos),
            'provider_nombre'    => $this->nombre_del_proveedor(),
        ];
    }

    /**
     * Nombre del proveedor de la compra escaneada, o null si la compra o el proveedor ya no
     * están.
     *
     * Sin nullsafe (PHP 7.4) y sin romperse: el aviso se queda sin el nombre del proveedor,
     * que es un detalle de presentación, pero llega igual. Un escaneo que no puede avisar
     * porque la compra se borró es una espera infinita del lado del usuario.
     *
     * @return string|null
     */
    protected function nombre_del_proveedor()
    {
        $provider_order = $this->provider_order;

        if (is_null($provider_order)) {
            return null;
        }

        $provider = $provider_order->provider;

        if (is_null($provider)) {
            return null;
        }

        return $provider->name;
    }
}
