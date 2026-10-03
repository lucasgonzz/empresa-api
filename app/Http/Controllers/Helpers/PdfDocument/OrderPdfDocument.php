<?php

namespace App\Http\Controllers\Helpers\PdfDocument;

use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\Order\ComboEsquemaHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use App\Models\ArticleVariant;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Adaptador de un `Order` (pedido online de la tienda) para `ProfileDocumentPdf`.
 *
 * 🔴 EL TOTAL DEL PEDIDO ES CONSERVADOR, Y A PROPÓSITO (decisión D6 de la misión). `orders.total`
 * guarda SOLO el subtotal de los renglones: no incluye el envío, el cupón ni el recargo o
 * descuento del medio de pago, y los ajustes por cliente (`discount_order` / `order_surchage`)
 * ya viajan adentro del precio de cada renglón, así que imprimirlos como renglón los contaría
 * dos veces. Portar el desglose completo de `tienda-api` (`OrderTotalsHelper::breakdown()`) crea
 * un acoplamiento nuevo entre dos proyectos sin poder verificarlo contra lo que cobró Mercado
 * Pago. Por eso:
 *
 *  - pedido sin envío, cupón ni ajuste por medio de pago → la línea se rotula "Total";
 *  - pedido con alguno → se rotula "Subtotal" y debajo van esas cosas como líneas INFORMATIVAS,
 *    sin sumarlas. Imprimir un "Total" que no es lo que pagó el comprador es peor que rotularlo
 *    "Subtotal".
 *
 * Si alguien quiere "arreglar" esto sumando las líneas: antes hay que poder verificar la cuenta
 * contra lo que cobró la pasarela de pago.
 */
class OrderPdfDocument implements PdfDocumentSource
{
    /**
     * Pedido que se imprime.
     *
     * @var \App\Models\Order
     */
    private $order;

    /**
     * Renglones ya armados (memoizados).
     *
     * @var array|null
     */
    private $items = null;

    /**
     * Variantes de los renglones, por id (una sola consulta para todo el pedido).
     *
     * @var array|null
     */
    private $variants = null;

    /**
     * @param \App\Models\Order $order
     */
    public function __construct($order)
    {
        $this->order = $order;
    }

    /**
     * El pedido adaptado. Lo lee el diseño con cajas (`CamposDePedidoPdf`) para los datos que el
     * PDF de siempre no imprime (estado, entrega, medio de pago, vendedor, depósito).
     *
     * @return \App\Models\Order
     */
    public function order()
    {
        return $this->order;
    }

    /** @return string */
    public function model_name()
    {
        return 'order';
    }

    /**
     * Dueño del pedido. `orders.user_id` no admite null; el respaldo es el mismo que usaba el
     * `OrderPdf` de siempre (el usuario de la sesión, o el de la instalación en una ruta pública).
     *
     * @return int
     */
    public function owner_id()
    {
        return $this->order->user_id ? (int) $this->order->user_id : (int) UserHelper::userId();
    }

    /** @return string */
    public function title()
    {
        return 'Pedido online';
    }

    /**
     * Objeto que imita a una venta para el encabezado. NO se le pasa el pedido tal cual:
     * `orders.address` es un TEXTO (la dirección de entrega) y no una relación con sucursal, y
     * `resolve_logo_url()` lee `->image_url` de lo que reciba en `address`.
     *
     * @return \stdClass
     */
    public function header_document()
    {
        $document = new \stdClass();
        $document->num = $this->order->num;
        $document->created_at = $this->order->created_at;
        /** Sin sucursal: el logo y los datos fiscales salen del dueño. */
        $document->address = null;
        $document->seller = null;
        $document->employee = null;
        $document->client = $this->header_client();

        return $document;
    }

    /**
     * Comprador del pedido con la forma de un cliente (nombre, teléfono, dirección, CUIT,
     * localidad). El CUIT sale del cliente vinculado (`buyers.comercio_city_client_id`); `buyers`
     * no tiene columna `cuit`, y `Buyer->client` NO existe (solo `comercio_city_client`).
     *
     * @return \stdClass|null Null si el pedido no tiene comprador (no se dibuja el bloque).
     */
    private function header_client()
    {
        $buyer = $this->order->buyer;

        if (is_null($buyer)) {
            return null;
        }

        $linked_client = $buyer->comercio_city_client;
        $city = $buyer->city ?: $buyer->ciudad;

        $client = new \stdClass();
        $client->name = trim($buyer->name.' '.$buyer->surname);
        $client->phone = (string) $buyer->phone;
        /** La dirección del pedido (la de entrega) gana; si no la trae, la del comprador. */
        $client->address = trim((string) $this->order->address) !== ''
            ? (string) $this->order->address
            : (string) $buyer->address;
        $client->cuit = $linked_client ? (string) $linked_client->cuit : '';
        $client->location = $city ? (object) ['name' => (string) $city] : null;
        $client->iva_condition = null;
        $client->description = null;

        return $client;
    }

    /**
     * Artículos, promociones y combos, en ese orden. Los combos salen por `ComboEsquemaHelper`
     * (guarda de esquema de `order_combo`).
     *
     * @return array
     */
    public function items()
    {
        if (! is_null($this->items)) {
            return $this->items;
        }

        $items = [];

        foreach ($this->order->articles as $item) {
            $items[] = $item;
        }
        foreach ($this->order->promocion_vinotecas as $item) {
            $items[] = $item;
        }
        foreach (ComboEsquemaHelper::combos_del_pedido($this->order) as $item) {
            $items[] = $item;
        }

        $this->items = $items;

        return $this->items;
    }

    /**
     * @param bool $with_images
     * @param bool $with_relations
     * @return void
     */
    public function preload_item_relations($with_images, $with_relations)
    {
        $articles = new EloquentCollection();
        foreach ($this->items() as $item) {
            if ($item instanceof Article) {
                $articles->push($item);
            }
        }

        if ($articles->count() < 1) {
            return;
        }

        if ($with_relations) {
            $articles->loadMissing(['brand', 'category', 'sub_category', 'provider']);
        }

        if ($with_images) {
            $articles->load(['images' => function ($query) {
                $query->orderBy('id', 'asc');
            }]);
        }
    }

    /**
     * Nombre del renglón. Los renglones de un pedido NO tienen `pivot->name` ni
     * `pivot->variant_description` (esos son de `article_sale`): el nombre es el del artículo y,
     * si el comprador eligió una variante, su descripción va detrás.
     *
     * @param object $item
     * @return string
     */
    public function item_name($item)
    {
        $name = (string) $item->name;
        $variant_id = isset($item->pivot->variant_id) ? $item->pivot->variant_id : null;

        if (! is_null($variant_id)) {
            $variants = $this->variants_by_id();
            $variant = isset($variants[$variant_id]) ? $variants[$variant_id] : null;

            if ($variant && trim((string) $variant->variant_description) !== '') {
                $name .= ' '.$variant->variant_description;
            }
        }

        return $name;
    }

    /**
     * Precio por cantidad. Sin bonificación: los ajustes por cliente ya vienen en el precio.
     *
     * @param object $item
     * @return float
     */
    public function item_subtotal($item)
    {
        return (float) $item->pivot->price * (float) $item->pivot->amount;
    }

    /**
     * Las notas del pedido (`orders.description`).
     *
     * @return string|null
     */
    public function observations()
    {
        $description = $this->order->description;

        return trim((string) $description) === '' ? null : (string) $description;
    }

    /** @return string */
    public function observations_title()
    {
        return 'NOTAS DEL PEDIDO';
    }

    /**
     * Subtotal (o Total, ver el encabezado de la clase) más las líneas informativas. El flag
     * `show_subtotal_in_footer` no aplica: acá no hay descuentos que un subtotal separe de un
     * total, y la línea de importe es la única que existe.
     *
     * @param array $flags
     * @return array
     */
    public function totals_rows($flags)
    {
        if (empty($flags['show_total_in_footer'])) {
            return [];
        }

        /**
         * Los renglones salen de las mismas piezas que usa el diseño con cajas
         * (`CamposDePedidoPdf`): así los dos PDF dicen lo mismo. Lo que devuelve este método no
         * cambió al partirlo (lo cuida el test 11 de tests/Feature/Pdf).
         */
        $extras = $this->extra_lines();

        $rows = [[
            'text' => (count($extras) > 0 ? 'Subtotal' : 'Total').': '.$this->texto_subtotal(),
            'bold' => true,
        ]];

        foreach ($extras as $extra) {
            $rows[] = ['text' => $extra, 'bold' => false];
        }

        return $rows;
    }

    /**
     * Lo que el pedido tiene además de sus renglones y que NO está en el subtotal: envío, cupón y
     * ajuste por medio de pago. Solo se cuenta lo que realmente cambia lo que paga el comprador
     * (un envío gratis no es un "extra").
     *
     * @return array Textos listos para imprimir.
     */
    private function extra_lines()
    {
        $lines = [];

        $envio = $this->texto_envio();
        if (! is_null($envio)) {
            $lines[] = 'Envío: '.$envio;
        }

        $cupon = $this->texto_cupon();
        if (! is_null($cupon)) {
            $lines[] = 'Cupón: '.$cupon;
        }

        $ajuste = $this->texto_ajuste_medio_de_pago();
        if (! is_null($ajuste)) {
            $lines[] = 'Medio de pago: '.$ajuste;
        }

        return $lines;
    }

    // ── Piezas de la caja de totales (las usan totals_rows() y el diseño con cajas) ──────────

    /**
     * Suma de los renglones (precio por cantidad).
     *
     * @return float
     */
    public function subtotal()
    {
        $subtotal = 0;
        foreach ($this->items() as $item) {
            $subtotal += $this->item_subtotal($item);
        }

        return $subtotal;
    }

    /**
     * Valor del Subtotal (o del Total, si no hay extras), sin el rótulo ("$4.150").
     *
     * @return string
     */
    public function texto_subtotal()
    {
        return '$'.Numbers::price($this->subtotal());
    }

    /**
     * ¿El pedido tiene envío con costo, cupón o ajuste por medio de pago? Con alguno, la línea de
     * importe se rotula "Subtotal" y no "Total" (decisión D6, ver el encabezado de la clase).
     *
     * @return bool
     */
    public function tiene_extras()
    {
        return count($this->extra_lines()) > 0;
    }

    /**
     * Valor del envío, sin el rótulo ("$500"), o null si no tiene costo. El envío por correo
     * (`envio_precio`, lo que cobró el servidor de la tienda) o, si no hay, el de la zona de entrega
     * propia del comercio.
     *
     * @return string|null
     */
    public function texto_envio()
    {
        $envio = null;
        if (! empty($this->order->envio_opcion) && is_numeric($this->order->envio_precio)) {
            $envio = (float) $this->order->envio_precio;
        } elseif (! is_null($this->order->delivery_zone)) {
            $envio = (float) $this->order->delivery_zone->price;
        }

        if (is_null($envio) || $envio <= 0) {
            return null;
        }

        return '$'.Numbers::price($envio);
    }

    /**
     * Valor del cupón, sin el rótulo (su código, o "aplicado" si ya no se encuentra), o null si el
     * pedido no usó cupón.
     *
     * @return string|null
     */
    public function texto_cupon()
    {
        if (is_null($this->order->cupon_id)) {
            return null;
        }

        $cupon = $this->order->cupon;

        return $cupon && ! empty($cupon->code) ? $cupon->code : 'aplicado';
    }

    /**
     * Descuento o recargo por medio de pago, sin el rótulo ("-10%" / "+5%"), o null. Uno solo,
     * nunca los dos (así lo resuelve la tienda).
     *
     * @return string|null
     */
    public function texto_ajuste_medio_de_pago()
    {
        if ((float) $this->order->payment_method_discount > 0) {
            return '-'.Numbers::price($this->order->payment_method_discount).'%';
        }

        if ((float) $this->order->payment_method_surchage > 0) {
            return '+'.Numbers::price($this->order->payment_method_surchage).'%';
        }

        return null;
    }

    /**
     * Variantes de todos los renglones del pedido, por id, en una sola consulta.
     *
     * @return array
     */
    private function variants_by_id()
    {
        if (! is_null($this->variants)) {
            return $this->variants;
        }

        $ids = [];
        foreach ($this->items() as $item) {
            if (isset($item->pivot->variant_id) && ! is_null($item->pivot->variant_id)) {
                $ids[] = $item->pivot->variant_id;
            }
        }

        $this->variants = [];
        if (count($ids) > 0) {
            foreach (ArticleVariant::whereIn('id', $ids)->get() as $variant) {
                $this->variants[$variant->id] = $variant;
            }
        }

        return $this->variants;
    }
}
