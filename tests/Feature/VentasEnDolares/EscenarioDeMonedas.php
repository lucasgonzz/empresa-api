<?php

namespace Tests\Feature\VentasEnDolares;

use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Models\Article;
use App\Models\Discount;
use App\Models\ExtencionEmpresa;
use App\Models\Sale;
use App\Models\SaleTax;
use App\Models\Surchage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ayudas compartidas de la suite `VentasEnDolares` (parte "artículos, guardado de venta y
 * ganancias").
 *
 * ESCENARIO DE 2R. Un cliente real usa la extensión `ventas_en_dolares` con
 * `users.cotizar_precios_en_dolares = 0`: NO cotiza los precios de sus artículos en dólares. Con
 * esa configuración un artículo con `cost_in_dollars = 1` conserva su `final_price` EN DÓLARES
 * (`ArticleHelper::cotizar()` solo multiplica por el dólar si `cotizar_precios_en_dolares` = 1), y
 * lo que decide en qué moneda se cobra cada renglón es la moneda de la VENTA.
 *
 * LO QUE LA API RECIBE. La SPA convierte el precio a la moneda de la venta ANTES de mandarlo
 * (`convertir_precio_a_moneda_de_la_venta()` de `empresa-spa/src/mixins/generals.js`): el
 * `items[].price_vender` que llega a `POST api/sale` ya está en la moneda de la venta, y la API lo
 * persiste tal cual. Estas ayudas emulan esa conversión con `price_vender_para()`. La SPA tampoco
 * redondea (decisión del 11/8/2026: "la SPA no redondea"), así que se manda el número sin redondear.
 * Y el `sub_total`/`total` también los arma la SPA: la API guarda los que le llegan
 * (`SaleController::store()` no los recalcula), así que este trait los calcula con
 * `totales_de_la_venta()`.
 *
 * DETERMINISMO. La base del slot trae una percepción IIBB del 3,5 % activa para el dueño 500
 * (`sale_taxes`), que infla todo `final_price` por división (1500 pasa a 1554,40). Para que los
 * números de las pruebas sean los que dice la cuenta, `preparar_escenario_2r()` la desactiva
 * (dentro de la transacción del test: `DatabaseTransactions` la devuelve). Un test aparte
 * (`Articulos_En_Ambas_Monedas_Test`) la deja prendida a propósito para verificar que el impuesto
 * se aplica igual en las dos monedas.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
trait EscenarioDeMonedas
{
    /** Moneda pesos. */
    protected $PESO = 1;

    /** Moneda dólares. */
    protected $DOLAR = 2;

    /** Dólar "de venta" que viaja en el payload y se guarda en `sales.valor_dolar`. */
    protected $VALOR_DOLAR = 1200;

    /** @var \App\Models\User El dueño del fixture (id 500). */
    protected $dueno;

    /** @var int Segundos que este trait le fue sumando al reloj del test (10 por venta). */
    protected $segundos_de_reloj = 0;

    /** @var \Carbon\Carbon|null Instante base de este test. */
    protected $reloj_base = null;

    /**
     * Ids de artículos creados por API en este test (para limpiar, por si el rollback no aplicara).
     *
     * @var int[]
     */
    protected $articulos_creados = [];

    /**
     * Deja la cuenta como la de 2R: extensión `ventas_en_dolares`, `cotizar_precios_en_dolares = 0`,
     * listas de precio apagadas, sin percepciones (ver el docblock del trait) y con el reloj fijo
     * en una fecha lejana para poder consultar listados/reportes por un rango propio.
     *
     * @param  string  $fecha  Instante base del escenario (cada test usa un día distinto).
     * @param  array   $usuario_overrides  Columnas de `users` a pisar (ej. `dollar`).
     * @return void
     */
    protected function preparar_escenario_2r($fecha, $usuario_overrides = [])
    {
        $this->dueno = User::where('email', \Database\Seeders\testing\TestingFerreteriaSeeder::USER_EMAIL)->first();

        User::where('id', $this->dueno->id)->update(array_merge([
            'cotizar_precios_en_dolares' => 0,
            'listas_de_precio'           => 0,
            'dollar'                     => 1000,
        ], $usuario_overrides));

        $extension = ExtencionEmpresa::where('slug', 'ventas_en_dolares')->first();

        if (is_null($extension)) {
            $extension = ExtencionEmpresa::forceCreate([
                'slug' => 'ventas_en_dolares',
                'name' => 'Ventas en dolares',
            ]);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extension->id]);

        $this->fijar_percepcion_iibb(false);

        $this->dueno = User::find($this->dueno->id);

        $this->reloj_base = Carbon::parse($fecha);
        $this->segundos_de_reloj = 0;
        Carbon::setTestNow($this->reloj_base->copy());
    }

    /**
     * Prende o apaga las percepciones sobre ventas (IIBB) del due�o y vac�a el cach� en memoria que
     * `ArticlePricesHelper::get_sale_taxes_para_articulo()` guarda POR PROCESO
     * (`static $sale_taxes_cache`): sin vaciarlo, el primer test que calcula un precio fija el
     * estado de las percepciones para todos los que corran despu�s en el mismo PHPUnit.
     *
     * @param  bool  $activa
     * @return void
     */
    protected function fijar_percepcion_iibb($activa)
    {
        SaleTax::where('user_id', $this->dueno->id)->update(['activo' => $activa ? 1 : 0]);

        ArticlePricesHelper::$sale_taxes_cache = [];
    }

    /**
     * Devuelve el reloj a la hora real. Se llama desde el tearDown de cada clase.
     *
     * @return void
     */
    protected function limpiar_escenario_2r()
    {
        Carbon::setTestNow(null);
        ArticlePricesHelper::$sale_taxes_cache = [];
        $this->reloj_base = null;
        $this->segundos_de_reloj = 0;

        $this->articulos_creados = [];
    }

    /**
     * Corre el reloj 10 segundos: la guarda anti-duplicados de `SaleController::venta_ya_cread()`
     * descarta en silencio (200 vacío) una venta con mismo cliente/empleado/total creada dentro de
     * los 5 segundos.
     *
     * @return void
     */
    protected function avanzar_reloj()
    {
        $this->segundos_de_reloj += 10;
        Carbon::setTestNow($this->reloj_base->copy()->addSeconds($this->segundos_de_reloj));
    }

    /**
     * Alta de artículo por `POST api/article` (nunca `Article::create`, que se saltearía el cálculo
     * del precio). El payload trae las columnas NOT NULL que `store()` asigna derecho del request.
     *
     * @param  string  $nombre
     * @param  array   $campos  `cost`, `cost_in_dollars`, `percentage_gain`, `price`, `aplicar_iva`,
     *                          `iva_id`... (pisan los defaults).
     * @param  float|null  $stock  Stock inicial (por query: el alta no lo asigna). Null = sin stock.
     * @return \App\Models\Article  Fila fresca de la base.
     */
    protected function crear_articulo($nombre, $campos = [], $stock = null)
    {
        $payload = array_merge([
            'name'                           => $nombre,
            'cost'                           => 1000,
            'cost_in_dollars'                => 0,
            'percentage_gain'                => 50,
            'online'                         => 0,
            'precio_pausado'                 => 0,
            'aplicar_iva'                    => 0,
            'apply_provider_percentage_gain' => 0,
            'status'                         => 'active',
            'in_offer'                       => 0,
            'default_in_vender'              => 0,
            'cost_incluye_iva'               => 0,
            'price_types'                    => [],
            'price_type_monedas'             => [],
            'tags'                           => [],
        ], $campos);

        $response = $this->postJson('api/article', $payload);

        $this->assertEquals(
            201,
            $response->getStatusCode(),
            'POST api/article no devolvio 201. Cuerpo: '.substr($response->getContent(), 0, 600)
        );

        $id = (int) $response->json('model.id');

        $this->articulos_creados[] = $id;

        if (!is_null($stock)) {
            Article::where('id', $id)->update(['stock' => $stock]);
        }

        return Article::find($id);
    }

    /**
     * El precio de UN artículo tal como la SPA se lo manda a la API para una venta en la moneda
     * pedida. Espejo de `convertir_precio_a_moneda_de_la_venta()` (generals.js) con
     * `owner.cotizar_precios_en_dolares = 0`:
     *
     *  - venta en USD: artículo SIN cost_in_dollars -> precio / valor_dolar; CON cost_in_dollars ->
     *    el precio queda igual (ya está en dólares).
     *  - venta en pesos: artículo CON cost_in_dollars -> precio * valor_dolar; sin él queda igual.
     *
     * Sin redondear (la SPA no redondea).
     *
     * @param  \App\Models\Article  $articulo
     * @param  int    $moneda_id
     * @param  float  $valor_dolar
     * @param  float|null  $precio_de_catalogo  Por defecto el `final_price` guardado.
     * @return float
     */
    protected function price_vender_para($articulo, $moneda_id, $valor_dolar, $precio_de_catalogo = null)
    {
        $precio = is_null($precio_de_catalogo) ? (float) $articulo->final_price : (float) $precio_de_catalogo;

        if ($moneda_id == 2) {
            return $articulo->cost_in_dollars ? $precio : $precio / (float) $valor_dolar;
        }

        return $articulo->cost_in_dollars ? $precio * (float) $valor_dolar : $precio;
    }

    /**
     * Un renglón de venta como lo arma la SPA: el artículo entero (con `cost`, `costo_real` y
     * `cost_in_dollars`, que es lo que lee `SaleHelper::getCost()`) más `is_article`, `amount` y
     * `price_vender`.
     *
     * @param  \App\Models\Article  $articulo
     * @param  float  $amount
     * @param  float  $price_vender  Ya convertido a la moneda de la venta.
     * @param  array  $extra  Claves a pisar o agregar (ej. `pivot`).
     * @return array
     */
    protected function item($articulo, $amount, $price_vender, $extra = [])
    {
        return array_merge($articulo->getAttributes(), [
            'is_article'   => true,
            'amount'       => $amount,
            'price_vender' => $price_vender,
        ], $extra);
    }

    /**
     * `sub_total` y `total` de la venta como los arma la SPA: suma de precio por cantidad de los
     * renglones y, encima, cada descuento resta y cada recargo suma su porcentaje sobre el
     * acumulado. Sin redondear.
     *
     * @param  array  $items
     * @param  array  $descuentos  `[['id' => .., 'percentage' => ..], ...]`
     * @param  array  $recargos    idem
     * @return array  `['sub_total' => float, 'total' => float]`
     */
    protected function totales_de_la_venta($items, $descuentos = [], $recargos = [])
    {
        $sub_total = 0.0;

        foreach ($items as $item) {
            $sub_total += (float) $item['price_vender'] * (float) $item['amount'];
        }

        $total = $sub_total;

        foreach ($descuentos as $d) {
            $total -= $total * (float) $d['percentage'] / 100;
        }

        foreach ($recargos as $r) {
            $total += $total * (float) $r['percentage'] / 100;
        }

        return ['sub_total' => $sub_total, 'total' => $total];
    }

    /**
     * Payload de `POST api/sale` de una venta de mostrador (sin cliente, sin cobro), en la moneda
     * dada. Calcado de `tests/Feature/Vender/4_Venta_sin_lista_de_precios_Test.php`.
     *
     * @param  int    $moneda_id
     * @param  float|null  $valor_dolar  Null = la clave `valor_dolar` NO viaja.
     * @param  array  $items
     * @param  array  $overrides  Claves a pisar (`discounts`, `surchages`, `total`...).
     * @return array
     */
    protected function payload_venta($moneda_id, $valor_dolar, $items, $overrides = [])
    {
        $descuentos = isset($overrides['discounts']) ? $overrides['discounts'] : [];
        $recargos   = isset($overrides['surchages']) ? $overrides['surchages'] : [];

        $totales = $this->totales_de_la_venta($items, $descuentos, $recargos);

        $payload = array_merge([
            'client_id'                  => null,
            'address_id'                 => null,
            'save_current_acount'        => 0,
            'omitir_en_cuenta_corriente' => 0,
            'to_check'                   => 0,
            'price_type_id'              => null,
            'discounts_in_services'      => 1,
            'surchages_in_services'      => 1,
            'employee_id'                => null,
            'sub_total'                  => $totales['sub_total'],
            'total'                      => $totales['total'],
            'terminada'                  => 1,
            'seller_id'                  => null,
            'cantidad_cuotas'            => null,
            'cuota_descuento'            => 0,
            'cuota_recargo'              => 0,
            'caja_id'                    => null,
            'afip_tipo_comprobante_id'   => null,
            'descuento'                  => null,
            'moneda_id'                  => $moneda_id,
            'discount_stock'             => 1,
            'discounts'                  => [],
            'surchages'                  => [],
            'items'                      => $items,
        ], $overrides);

        if (!is_null($valor_dolar)) {
            $payload['valor_dolar'] = $valor_dolar;
        }

        return $payload;
    }

    /**
     * Postea la venta, exige 201 y devuelve la `Sale` fresca. Avanza el reloj antes de postear.
     *
     * @param  array  $payload
     * @return \App\Models\Sale
     */
    protected function guardar_venta($payload)
    {
        $this->avanzar_reloj();

        $response = $this->postJson('api/sale', $payload);

        $this->assertEquals(
            201,
            $response->getStatusCode(),
            'POST api/sale no devolvio 201. Cuerpo: '.substr($response->getContent(), 0, 800)
        );

        $id = (int) $response->json('model.id');

        $this->assertGreaterThan(0, $id, 'POST api/sale no devolvio el id de la venta. Cuerpo: '.substr($response->getContent(), 0, 500));

        return Sale::find($id);
    }

    /**
     * La fila de `article_sale` (pivot) de un artículo en una venta, tal cual está en la base.
     *
     * @param  \App\Models\Sale  $venta
     * @param  \App\Models\Article  $articulo
     * @return object|null
     */
    protected function pivot_de($venta, $articulo)
    {
        return DB::table('article_sale')
            ->where('sale_id', $venta->id)
            ->where('article_id', $articulo->id)
            ->first();
    }

    /**
     * Todas las filas de `article_sale` de una venta.
     *
     * @param  \App\Models\Sale  $venta
     * @return \Illuminate\Support\Collection
     */
    protected function pivots_de($venta)
    {
        return DB::table('article_sale')->where('sale_id', $venta->id)->get();
    }

    /**
     * Descuento de la venta del propio test (no el `Descuento e2e` del fixture).
     *
     * @param  float  $porcentaje
     * @return \App\Models\Discount
     */
    protected function crear_descuento($porcentaje)
    {
        return Discount::forceCreate([
            'name'       => 'zz Descuento '.$porcentaje.' (monedas)',
            'percentage' => $porcentaje,
            'user_id'    => $this->dueno->id,
        ]);
    }

    /**
     * Recargo de la venta del propio test.
     *
     * @param  float  $porcentaje
     * @return \App\Models\Surchage
     */
    protected function crear_recargo($porcentaje)
    {
        return Surchage::forceCreate([
            'name'       => 'zz Recargo '.$porcentaje.' (monedas)',
            'percentage' => $porcentaje,
            'user_id'    => $this->dueno->id,
        ]);
    }

    /**
     * Stock actual (columna `articles.stock`) leído fresco de la base.
     *
     * @param  \App\Models\Article  $articulo
     * @return float
     */
    protected function stock_de($articulo)
    {
        return (float) DB::table('articles')->where('id', $articulo->id)->value('stock');
    }

    /**
     * Un renglón de una venta YA GUARDADA tal como la SPA lo manda en la edición
     * (`PUT api/sale/{id}`): el precio sale del pivot (`price_desde_pivot` de
     * `check_moneda()`: convertirlo otra vez sería cotizar dos veces) y el renglón trae `pivot`
     * con el costo guardado, que es lo que `SaleHelper::getCost()` devuelve tal cual.
     *
     * @param  \App\Models\Article  $articulo
     * @param  \App\Models\Sale  $venta
     * @param  float  $amount  Cantidad nueva del renglón.
     * @return array
     */
    protected function item_de_edicion($articulo, $venta, $amount)
    {
        $p = $this->pivot_de($venta, $articulo);

        return $this->item($articulo, $amount, (float) $p->price, [
            'pivot' => [
                'price'  => $p->price,
                'cost'   => $p->cost,
                'amount' => $p->amount,
            ],
        ]);
    }

    /**
     * Payload mínimo de `PUT api/sale/{id}` (calcado de `Vender/4_Venta_sin_lista_de_precios_Test`).
     *
     * @param  \App\Models\Sale  $venta
     * @param  array  $items
     * @param  array  $overrides
     * @return array
     */
    protected function payload_edicion_venta($venta, $items, $overrides = [])
    {
        $totales = $this->totales_de_la_venta($items);

        return array_merge([
            'client_id'                  => null,
            'save_current_acount'        => 0,
            'omitir_en_cuenta_corriente' => 0,
            'to_check'                   => 0,
            'checked'                    => 0,
            'confirmed'                  => 0,
            'discounts_in_services'      => 1,
            'surchages_in_services'      => 1,
            'discount_stock'             => 1,
            'sub_total'                  => $totales['sub_total'],
            'total'                      => $totales['total'],
            'moneda_id'                  => $venta->moneda_id,
            'valor_dolar'                => $venta->valor_dolar,
            'items'                      => $items,
            'discounts'                  => [],
            'surchages'                  => [],
            'returned_items'             => [],
        ], $overrides);
    }
}
