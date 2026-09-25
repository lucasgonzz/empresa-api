<?php

namespace App\Models;

use App\Http\Controllers\Helpers\AjustesDeClienteEsquemaHelper;
use App\Http\Controllers\Helpers\Order\ComboEsquemaHelper;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $guarded = [];

    /**
     * Los tres json del envío por correo (misión zipnova-envios, 14/9/2026): la cotización que hizo
     * el servidor de la tienda, la opción que eligió el comprador y el destinatario con su dirección.
     * Los escribe `tienda-api` al crear el pedido (copiados del carrito); el ERP los lee para
     * generar el envío en Zipnova y para mostrarlos en el modal del pedido. `envio_precio` y
     * `envio_proveedor` son columnas planas y no necesitan cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'envio_cotizacion' => 'array',
        'envio_opcion'     => 'array',
        'envio_destino'    => 'array',
    ];

    /**
     * `envio` va en el withAll porque el listado de pedidos y el modal lo muestran en cada fila
     * (estado del envío, número de seguimiento). Es un hasOne al más nuevo, así que cuesta una
     * sola consulta para todo el listado.
     *
     * 🔴 `combos.articles` entra por `ComboEsquemaHelper::relaciones_de_combos()` y no como una
     * cadena mas de la lista: en un cliente que todavia no corrio la migracion de `order_combo`,
     * pedirlo a secas tira `Base table or view not found` y el LISTADO DE PEDIDOS deja de abrir.
     * La lista se arma en un array justamente para poder agregarlo condicionalmente.
     */
    function scopeWithAll($query) {

        // `buyer.comercio_city_client` (misión vincular-comprador-desde-pedidos, 24/9/2026): la tabla
        // de Pedidos marca "Sin vincular" al comprador que no tiene un cliente del sistema vivo, y
        // para eso necesita el cliente cargado. Va la relación COMPLETA, sin restringir columnas:
        // `ProcessArchivoDeIntercambioPedidos` lee `->num` y `->price_type_id` de ese cliente. Un
        // cliente borrado (SoftDeletes) llega como null, igual que en `CreateSaleOrderHelper`.
        $relaciones = ['order_status', 'articles.images', 'articles.colors', 'articles.sizes', 'cupon', 'buyer', 'buyer.comercio_city_client', 'payment_method.payment_method_type', 'delivery_zone', 'payment_card_info', 'promocion_vinotecas.images', 'envio'];

        $query->with(array_merge(
            $relaciones,
            ComboEsquemaHelper::relaciones_de_combos(),
            AjustesDeClienteEsquemaHelper::relaciones_de_pedido()
        ));
    }

    /**
     * Descuentos del cliente con los que la tienda priceó este pedido (misión
     * descuentos-recargos-por-cliente, 23/9/2026), tabla `discount_order`. Los escribe `tienda-api`
     * al crear el pedido; el ERP los muestra en el pedido y se los pasa a la venta al confirmar.
     *
     * `withTrashed()` y `percentage` en el pivot van juntos: si el dueño borra o cambia el
     * descuento después de que entró el pedido, el pedido tiene que seguir mostrando —y la venta
     * recibiendo— el porcentaje con el que se calcularon sus precios, no el de hoy. Sin el
     * `withTrashed()` el descuento borrado desaparecería de la relación y la venta nacería con los
     * renglones mal (ver `CreateSaleOrderHelper::ajustes_del_pedido()`).
     *
     * Leela por `CreateSaleOrderHelper::ajustes_del_pedido()` o con la guarda de
     * `AjustesDeClienteEsquemaHelper`: en una base sin la tabla, acceder a la relación revienta.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    function discounts() {
        return $this->belongsToMany(Discount::class)->withTrashed()->withPivot('percentage');
    }

    /**
     * Recargos del cliente con los que la tienda priceó este pedido, tabla `order_surchage`.
     * Mismo criterio que `discounts()`.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    function surchages() {
        return $this->belongsToMany(Surchage::class)->withTrashed()->withPivot('percentage');
    }

    function promocion_vinotecas() {
        return $this->belongsToMany(PromocionVinoteca::class)->withTrashed()->withPivot('cost', 'price', 'amount', 'notes');
    }

    /**
     * Combos comprados en el pedido (mision combos-y-rangos-de-precio, 16/9/2026).
     *
     * 🔴 LA TABLA VA EXPLICITA. La convencion de Laravel arma el nombre del pivote ordenando los dos
     * modelos alfabeticamente, o sea `combo_order`, y esa tabla NO existe: la migracion de esta
     * mision crea `order_combo`, para que sea gemela de `cart_combo` y quede en la misma familia
     * que `order_promocion_vinoteca`. Sin el segundo argumento, cualquier acceso a esta relacion
     * consulta una tabla inexistente.
     *
     * Las cuatro columnas del pivote son las mismas que declara `articles()` para `article_order` y
     * `promocion_vinotecas()` para `order_promocion_vinoteca`. `cost` hace falta de verdad: es el
     * costo CONGELADO al momento de la compra, y `CreateSaleOrderHelper` lo pasa a la venta para que
     * el margen no se recalcule con el costo de hoy.
     *
     * `withTrashed()` por el mismo motivo que en `Budget::combos()`: `Combo` usa SoftDeletes, y un
     * combo dado de baja despues de vendido tiene que seguir apareciendo en el pedido viejo y en su
     * total. Si desapareciera de la relacion pero siguiera contando en `orders.total`, el pedido
     * quedaria descuadrado sin que nada lo explique.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    function combos() {
        return $this->belongsToMany(Combo::class, 'order_combo')->withTrashed()->withPivot('amount', 'price', 'cost', 'notes');
    }

    function articles() {
        return $this->belongsToMany('App\Models\Article')->withTrashed()->withPivot('cost', 'price', 'amount', 'variant_id', 'color_id', 'size_id', 'with_dolar', 'address_id', 'notes');
    }

    function cart() {
        return $this->hasOne(Cart::class);
    }

    function payment_card_info() {
        return $this->belongsTo('App\Models\PaymentCardInfo');
    }

    function order_status() {
        return $this->belongsTo('App\Models\OrderStatus');
    }

    function cupon() {
        return $this->belongsTo('App\Models\Cupon');
    }

    function buyer() {
        return $this->belongsTo('App\Models\Buyer');
    }

    function payment_method() {
        return $this->belongsTo('App\Models\PaymentMethod');
    }

    function delivery_zone() {
        return $this->belongsTo('App\Models\DeliveryZone');
    }

    function user() {
        return $this->belongsTo('App\Models\User');
    }

    function payment() {
        return $this->hasOne('App\Models\Payment');
    }

    /**
     * El envío generado en Zipnova para este pedido: el MÁS NUEVO, porque un pedido puede tener
     * un envío cancelado (o uno que nunca se pudo crear) y después otro que sí viajó. `latestOfMany()`
     * (Laravel >= 8.42, acá 8.83) resuelve el eager load en una sola consulta, igual que
     * `Buyer::last_message()`.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    function envio() {
        return $this->hasOne(Envio::class)->latestOfMany();
    }
}
